#!/usr/bin/env php
<?php
declare(strict_types=1);

new class {
    const DEFAULT_INPUT_PATH = 'input.mp4';
    const DEFAULT_OUTPUT_PATH = 'output.mkv';
    const DEFAULT_MIN_SILENCE_S = 0.5;
    const DEFAULT_NOISE_CUTOFF_DB = 40;

    function __construct() {
        $this->parseArgs();
        if ($this->show_usage) {
            $this->showUsage();
        } else {
            $gaps = $this->findSilentGaps();
            $this->concatSilentGaps($gaps);
        }
    }

    private function parseArgs(): void {
        $opt = getopt('i:o:t:n:h');
        $this->input_path = $opt['i'] ?? self::DEFAULT_INPUT_PATH;
        $this->output_path = $opt['o'] ?? self::DEFAULT_OUTPUT_PATH;
        $this->min_silence_s = (float)($opt['t'] ?? self::DEFAULT_MIN_SILENCE_S);
        $this->noise_cutoff_db = (int)($opt['n'] ?? self::DEFAULT_NOISE_CUTOFF_DB);
        $this->show_usage = isset($opt['h']);
    }

    private function showUsage(): void {
        echo "Usage: {$_SERVER['PHP_SELF']} " .
            "-i <input_path> " .
            "-o <output_path> " .
            "-t <min_silence_s>" .
            "-n <noise_thresh_db>" .
            "\n";
    }

    private function findSilentGaps(): array {
        $detect_cmd = sprintf(
            'ffmpeg -nostats -i %s -af silencedetect=d=%s:n=-%ddB -f null - 2>&1',
            escapeshellarg($this->input_path),
            $this->min_silence_s,
            $this->noise_cutoff_db
        );
        $output = [];
        $exit_code = 0;
        echo "Executing: $detect_cmd\n";
        exec($detect_cmd, $output, $exit_code);
        if ($exit_code !== 0) {
            echo implode("\n", $output);
            exit($exit_code);
        }
        $matches = [];
        $start = null;
        $gaps = [];
        foreach ($output as $line) {
            if (!preg_match('@silence_(start|end): (\d+\.\d+)@', $line, $matches)) {
                continue;
            }
            if ($matches[1] === 'start') {
                $start = (float)$matches[2];
            } else if ($start !== null) {
                $end = (float)$matches[2];
                $gaps[] = [ $start, $end ];
                $start = null;
            }
        }
        printf("Found %d gap(s) of silence\n", count($gaps));
        if (empty($gaps)) {
            exit(0);
        }
        return $gaps;
    }

    private function concatSilentGaps(array $gaps): void {
        $trims = [];
        $concats = [];
        for ($i = 0; $i < count($gaps); $i++) {
            list($start, $end) = $gaps[$i];
            $trims[] = sprintf(
                '[0:v]trim=%s:%s,setpts=PTS-STARTPTS[v%d]; ' .
                '[0:a]atrim=%s:%s,asetpts=PTS-STARTPTS[a%d]; ',
                $start, $end, $i,
                $start, $end, $i
            );
            $concats[] = sprintf('[v%s][a%d]', $i, $i);
        }

        $filter_complex =
            implode(' ', $trims) .
            implode('', $concats) .
            'concat=v=1:a=1:n=' . count($gaps) .
            '[out]';

        $concat_cmd = sprintf(
            'ffmpeg -i %s -filter_complex %s -map %s %s 2>&1',
            escapeshellarg($this->input_path),
            escapeshellarg($filter_complex),
            '[out]',
            escapeshellarg($this->output_path)
        );
        echo "Executing: $concat_cmd\n";
        pcntl_exec('/bin/sh', ['-c', $concat_cmd]);
    }
};
