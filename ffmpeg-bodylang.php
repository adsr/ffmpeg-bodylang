#!/usr/bin/env php
<?php
declare(strict_types=1);

new class {
    const DEFAULT_INPUT_PATH = 'input.mp4';
    const DEFAULT_OUTPUT_PATH = 'output.mkv';
    const DEFAULT_MIN_SILENCE_S = 0.7;
    const DEFAULT_NOISE_CUTOFF_DB = 37;

    function __construct() {
        $this->parseArgs();
        if ($this->show_usage) {
            $this->showUsage();
        } else {
            $gaps = $this->findSilentGaps();
            $this->concatSilentGaps($gaps);
            $this->applyAudioGain();
        }
    }

    private function parseArgs(): void {
        $opt = getopt('i:o:t:n:s:l:h');
        $this->input_path = $opt['i'] ?? self::DEFAULT_INPUT_PATH;
        $this->output_path = $opt['o'] ?? self::DEFAULT_OUTPUT_PATH;
        $ext = pathinfo($this->output_path, PATHINFO_EXTENSION) ?: 'mkv';
        $this->tmp_path = tempnam(sys_get_temp_dir(), 'ffmpeg-bodylang-') . ".{$ext}";
        $this->min_silence_s = (float)($opt['t'] ?? self::DEFAULT_MIN_SILENCE_S);
        $this->noise_cutoff_db = (int)($opt['n'] ?? self::DEFAULT_NOISE_CUTOFF_DB);
        $this->show_usage = isset($opt['h']);
        $this->time_start = $opt['s'] ?? null;
        $this->time_len = $opt['l'] ?? null;
    }

    private function showUsage(): void {
        echo "Usage: {$_SERVER['PHP_SELF']} " .
            "-i <input_path> " .
            "-o <output_path> " .
            "-t <min_silence_s> " .
            "-n <noise_thresh_db> " .
            "-s <time_start> " .
            "-l <time_len>" .
            "\n";
    }

    private function findSilentGaps(): array {
        $detect_cmd = sprintf(
            'ffmpeg -nostats -i %s %s %s -af silencedetect=d=%s:n=-%ddB -f null - 2>&1',
            escapeshellarg($this->input_path),
            $this->time_start ? '-ss ' . escapeshellarg($this->time_start) : '',
            $this->time_len ? '-t ' . escapeshellarg($this->time_len) : '',
            $this->min_silence_s,
            $this->noise_cutoff_db
        );
        $output = $this->myExec($detect_cmd);
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
        $anorms = [];
        $concats = [];
        for ($i = 0; $i < count($gaps); $i++) {
            list($start, $end) = $gaps[$i];
            $trims[] = sprintf(
                '[0:v]trim=%s:%s,setpts=PTS-STARTPTS[v%d]; ' .
                '[0:a]atrim=%s:%s,asetpts=PTS-STARTPTS[a%d]; ',
                $start, $end, $i,
                $start, $end, $i
            );
            $concats[] = sprintf('[v%d][a%d]', $i, $i);
        }

        $filter_complex =
            implode('', $trims) .
            implode('', $concats) . 'concat=v=1:a=1:n=' . count($concats) . '[out]';

        $concat_cmd = sprintf(
            'ffmpeg -y -i %s -filter_complex %s -map %s %s 2>&1',
            escapeshellarg($this->input_path),
            escapeshellarg($filter_complex),
            '[out]',
            escapeshellarg($this->tmp_path)
        );
        $this->myExec($concat_cmd);
    }

    private function applyAudioGain(): void {
        $voldet_cmd = sprintf(
            'ffmpeg -y -i %s -vn -af "volumedetect" -f null /dev/null 2>&1',
            escapeshellarg($this->tmp_path)
        );
        $output = $this->myExec($voldet_cmd);
        $vol = null;
        $matches = [];
        foreach ($output as $line) {
            if (preg_match('@max_volume: -(\d+\.\d+) dB@', $line, $matches)) {
                $vol = (int)floor((float)$matches[1] * 0.75);
                break;
            }
        }
        if ($vol === null) {
            printf("Failed to detect max_volume of %s\n", $this->tmp_path);
            exit(1);
        }
        $gain_cmd = sprintf(
            'ffmpeg -y -i %s -af "volume=%ddB" -c:v copy %s 2>&1 && rm %s 2>&1',
            escapeshellarg($this->tmp_path),
            $vol,
            escapeshellarg($this->output_path),
            escapeshellarg($this->tmp_path)
        );
        echo "Executing: $gain_cmd\n";
        pcntl_exec('/bin/sh', ['-c', $gain_cmd]);
    }

    private function myExec(string $cmd): array {
        $output = [];
        $exit_code = 0;
        echo "Executing: $cmd\n";
        exec($cmd, $output, $exit_code);
        if ($exit_code !== 0) {
            echo implode("\n", $output);
            exit($exit_code);
        }
        return $output;
    }
};
