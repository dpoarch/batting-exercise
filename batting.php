<?php

class Csv
{
    public $file;

    public $filepointer;

    protected $headers;

    protected $rows = [];

    public function __construct($file, array $headers = [], $fresh = false)
    {
        $this->file = $file;

        if (file_exists($file) === false) {
            throw new \Exception("File does not exist: {$file}");
        }

        $mode = ($fresh) ? "w+" : "a+";
        $this->filepointer = fopen($file, $mode);

        if ($this->filepointer === false) {
            throw new \Exception("Cannot open file: {$file}");
        }

        // @note always assume row 0 is headers
        $this->headers = fgetcsv($this->filepointer);

        if ($headers !== []) {
            $this->headers = $headers;
        }
    }

    public function count()
    {
        $currentPointer = ftell($this->filepointer);
        $this->rewind();
        $count = 0;

        while (!feof($this->filepointer)) {
            fgets($this->filepointer);
            $count++;
        }

        fseek($this->filepointer, $currentPointer);
        return $count;
    }

    public function getRow()
    {
        // @note we will fgets+explode for lesser overhead.
        // we will just assume that input csv is always properly formatted for now
        while ($line = fgets($this->filepointer)) {
            $line = explode(",", trim($line));
            yield array_combine($this->headers, $line);
        }
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function rewind()
    {
        rewind($this->filepointer);
        fgets($this->filepointer);

        return $this;
    }

    public function filter($callable, $make = false, $scan = false)
    {
        if (is_callable($callable) === false) {
            throw new \Exception("Provided callable is not callable");
        }

        $currentPointer = ftell($this->filepointer);

        if ($scan) {
            $this->rewind();
        }

        $filtered = ($make) ? static::make($this->headers) : [];

        foreach ($this->getRow() as $row) {
            if (call_user_func_array($callable, [$row]) === false) {
                continue;
            }

            ($make) ? $filtered->addRow($row) : $filtered[] = $row;
        }

        if (!$scan) {
            fseek($this->filepointer, $currentPointer);
        }

        return ($make) ? $filtered->rewind() : $filtered;
    }

    public function scan($callable, $make = false)
    {
        $currentPointer = ftell($this->filepointer);
        $result = $this->filter($callable, $make, true);
        fseek($this->filepointer, $currentPointer);

        return $result;
    }

    public function each($callable, $scan = false)
    {
        if (is_callable($callable) === false) {
            throw new \Exception("Provided callable is not callable");
        }

        $currentPointer = ftell($this->filepointer);

        if ($scan) {
            $this->rewind();
        }

        $filtered = [];

        foreach ($this->getRow() as $row) {
            if (call_user_func_array($callable, [$row]) === false) {
                continue;
            }

            $filtered[] = $row;
        }

        fseek($this->filepointer, $currentPointer);

        return $filtered;
    }

    public function find($callable)
    {
        if (is_callable($callable) === false) {
            throw new \Exception("Provided callable is not callable");
        }

        $currentPointer = ftell($this->filepointer);
        $this->rewind();

        foreach ($this->getRow() as $row) {
            if (call_user_func_array($callable, [$row]) === true) {
                fseek($this->filepointer, $currentPointer);
                return $row;
            }
        }

        return null;
    }

    public static function make(array $headers, $file = null)
    {
        $dir = sys_get_temp_dir();
        $file = ($file === null) ? tempnam($dir, 'csv.') : $file;

        $new = new static($file, $headers, true);
        $new->addRow($headers);

        return $new;
    }

    public function addRow($data)
    {
        fputcsv($this->filepointer, $data);

        return $this;
    }

    public function close()
    {
        fclose($this->filepointer);

        return unlink($this->file);
    }

    public function __destruct()
    {
        if (is_resource($this->filepointer)) {
            fclose($this->filepointer);
        }
    }
}

function main($args) {
    $filters = [];

    $options = getopt("h", [
        "year::",
        "team::",
        "help"
    ]);

    if ((count($args) === 1) || @$options['h'] === false || @$options['help'] === false) {
        echo sprintf("\n\tphp %s --year=YYYY --team=teamID <file>\n", $args[0]);
        exit(0);
    }

    if (isset($options['year'])) {
        $filters['yearID'] = $options['year'];
    }

    if (isset($options['team'])) {
        $filters['teamID'] = $options['team'];
    }

    $csv = new Csv($args[count($args) - 1]);
    // @note we will always assume there is a Teams.csv included
    $teams = new Csv(__DIR__ . '/Teams.csv');

    if ($filters) {
        $csv = $csv->filter(function ($row) use ($filters) {
            foreach ($filters as $key => $value) {
                if ($row[$key] != $value) {
                    return false;
                }
            }

            return true;
        }, true);

        $teams = $teams->filter(function ($row) use ($filters) {
            foreach ($filters as $key => $value) {
                if ($row[$key] != $value) {
                    return false;
                }
            }

            return true;
        }, true);
    }

    $output = Csv::make([
        'playerID',
        'yearID',
        'Team name(s)',
        'Batting Average',
    ], __DIR__ . '/result.csv');

    $playerSeasons = [];
    $total = $csv->count();
    $rowCount = 1;

    foreach ($csv->getRow() as $row) {
        $playerSeasonId = sprintf("%s::%s", $row['playerID'], $row['yearID']);

        if (in_array($playerSeasonId, $playerSeasons)) {
            continue;
        }

        $playerSeasons[] = $playerSeasonId;
        $atBats = 0;
        $hits = 0;
        $teamSeasons = [];

        // get all player's stint for that year
        $csv->scan(function ($player) use ($row, $teams, &$atBats, &$hits, &$teamSeasons) {
            if ($player['yearID'] !== $row['yearID'] || $player['playerID'] !== $row['playerID']) {
                return false;
            }

            $hits += $player['H'];
            $atBats += $player['AB'];

            // get all player's teams for each stint for that year
            $team = $teams->find(function ($team) use ($player) {
                return $team['teamID'] === $player['teamID'] && $team['yearID'] === $player['yearID'];
            });
            $teamSeasons[] = $team['name'];
            return true;
        });

        $battingAvg = ($atBats > 0) ? ($hits / $atBats) : 0;
        $team = trim(implode(', ', $teamSeasons), ', ');

        $output->addRow([
            'playerID' => $row['playerID'],
            'yearID' => $row['yearID'],
            'Team name(s)' => $team,
            'Batting Average' => number_format($battingAvg, 3)
        ]);

        $prcnt = number_format(($rowCount / $total) * 100, 2);
        echo "\rProcessing: {$prcnt}%";
        $rowCount++;
    }

    if ($filters) {
        $csv->close();
        $teams->close();
    }
}

main($argv);
