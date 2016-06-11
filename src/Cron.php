<?php

/**
 * Cron expression parser and validator
 *
 * @author René Pollesch
 */
class Cron
{
    /**
     * Weekday look-up table
     *
     * @var array
     */
    protected static $weekdays = [
        'sun' => 0,
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6
    ];

    /**
     * Month name look-up table
     *
     * @var array
     */
    protected static $months = [
        'jan' => 1,
        'feb' => 2,
        'mar' => 3,
        'apr' => 4,
        'may' => 5,
        'jun' => 6,
        'jul' => 7,
        'aug' => 8,
        'sep' => 9,
        'oct' => 10,
        'nov' => 11,
        'dec' => 12
    ];

    /**
     * Cron expression
     *
     * @var string
     */
    protected $expression;

    /**
     * Time zone
     *
     * @var \DateTimeZone
     */
    protected $timeZone;

    /**
     * Matching register
     *
     * @var array|null
     */
    protected $registers;

    /**
     * Class constructor sets cron expression property
     *
     * @param string $expression cron expression
     * @param \DateTimeZone $timeZone
     */
    public function __construct($expression = '* * * * *', \DateTimeZone $timeZone = null)
    {
        $this->setExpression($expression);
        $this->setTimeZone($timeZone);
    }

    /**
     * Set expression
     *
     * @param string $expression
     * @return self
     */
    public function setExpression($expression)
    {
        $this->expression = trim((string)$expression);
        $this->registers = null;

        return $this;
    }

    /**
     * Set time zone
     *
     * @param \DateTimeZone $timeZone
     * @return self
     */
    public function setTimeZone(\DateTimeZone $timeZone = null)
    {
        $this->timeZone = $timeZone;
        return $this;
    }

    /**
     * Parse and validate cron expression
     *
     * @return bool true if expression is valid, or false on error
     */
    public function isValid()
    {
        $result = true;

        if ($this->registers === null) {
            try {
                $this->registers = $this->parse();
            } catch (\Exception $e) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Match current or given date/time against cron expression
     *
     * @param mixed $dtime \DateTime object, timestamp or null
     * @return bool
     */
    public function isMatching($dtime = null)
    {
        if ($dtime instanceof \DateTime) {
            $dtime->setTimezone($this->timeZone);
        } else {
            $dt = new \DateTime('now', $this->timeZone);

            if ((int)$dtime > 0) {
                $dt->setTimestamp($dtime);
            }

            $dtime = $dt;
        }

        $segments = sscanf($dtime->format('i G j n w'), '%d %d %d %d %d');

        try {
            $result = true;

            foreach ($this->parse() as $i => $item) {
                if (isset($item[(int)$segments[$i]]) === false) {
                    $result = false;
                    break;
                }
            }
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    /**
     * Calculate next matching timestamp
     *
     * @param mixed $dtime \DateTime object, timestamp or null
     * @return int|bool next matching timestamp, or false on error
     */
    public function getNext($dtime = null)
    {
        $result = false;

        if ($this->isValid()) {
            if ($dtime instanceof \DateTime) {
                $timestamp = $dtime->getTimestamp();
            } elseif ((int)$dtime > 0) {
                $timestamp = $dtime;
            } else {
                $timestamp = time();
            }

            $dt = new \DateTime('now', $this->timeZone);
            $dt->setTimestamp(ceil($timestamp / 60) * 60);

            list($pday, $pmonth, $pyear, $phour) = sscanf(
                $dt->format('j n Y G'),
                '%d %d %d %d'
            );

            while ($result === false) {
                list($minute, $hour, $day, $month, $year, $weekday) = sscanf(
                    $dt->format('i G j n Y w'),
                    '%d %d %d %d %d %d'
                );

                if ($pyear !== $year) {
                    $dt->setDate($year, 1, 1);
                    $dt->setTime(0, 0);
                } elseif ($pmonth !== $month) {
                    $dt->setDate($year, $month, 1);
                    $dt->setTime(0, 0);
                } elseif ($pday !== $day) {
                    $dt->setTime(0, 0);
                } elseif ($phour !== $hour) {
                    $dt->setTime($hour, 0);
                }

                list($pday, $pmonth, $pyear, $phour) = [$day, $month, $year, $hour];

                if (isset($this->registers[3][$month]) === false) {
                    $dt->modify('+1 month');
                    continue;
                } elseif (false === (isset($this->registers[2][$day]) && isset($this->registers[4][$weekday]))) {
                    $dt->modify('+1 day');
                    continue;
                } elseif (isset($this->registers[1][$hour]) === false) {
                    $dt->modify('+1 hour');
                    continue;
                } elseif (isset($this->registers[0][$minute]) === false) {
                    $dt->modify('+1 minute');
                    continue;
                }

                $result = $dt->getTimestamp();
            }
        }

        return $result;
    }

    /**
     * Parse cron expression and return expression parsed into matchable registers
     *
     * @return array
     * @throws \Exception
     */
    private function parse()
    {
        $registers = [];

        if (sizeof($segments = preg_split('/\s+/', $this->expression)) === 5) {
            foreach ($segments as $index => $segment) {
                $this->parseSegment($index, $segment, $registers);
            }

            $registers[4][0] = isset($registers[4][7]);
        } else {
            throw new \Exception('invalid number of segments');
        }

        return $registers;
    }

    /**
     * @param int $index
     * @param string $segment
     * @param array $registers
     * @throws \Exception
     */
    private function parseSegment($index, $segment, &$registers)
    {
        $strv = [false, false, false, self::$months, self::$weekdays];

        // month names, weekdays
        if ($strv[$index] !== false && isset($strv[$index][strtolower($segment)])) {
            // cannot be used with lists or ranges, see crontab(5) man page
            $registers[$index][$strv[$index][strtolower($segment)]] = true;
        } else {
            // split up current segment into single elements, e.g. "1,5-7,*/2" => [ "1", "5-7", "*/2" ]
            foreach (explode(',', $segment) as $element) {
                $this->parseElement($index, $element, $registers);
            }
        }
    }

    /**
     * @param int $index
     * @param string $element
     * @param array $registers
     * @throws \Exception
     */
    private function parseElement($index, $element, &$registers)
    {
        $stepping = 1;
        $minv = [0, 0, 1, 1, 0];
        $maxv = [59, 23, 31, 12, 7];

        // parse stepping notation
        if (strpos($element, '/') !== false) {
            if (sizeof($stepsegments = explode('/', $element)) === 2) {
                $element = $stepsegments[0];

                if (is_numeric($stepsegments[1])) {
                    if ($stepsegments[1] > 0 && $stepsegments[1] <= $maxv[$index]) {
                        $stepping = intval($stepsegments[1]);
                    } else {
                        throw new \Exception('stepping value out of allowed range');
                    }
                } else {
                    throw new \Exception('non-numeric stepping notation');
                }
            } else {
                throw new \Exception('invalid stepping notation');
            }
        }

        // single value
        if (is_numeric($element)) {
            if (intval($element) < $minv[$index] || intval($element) > $maxv[$index]) {
                throw new \Exception('value out of allowed range');
            }

            if ($stepping !== 1) {
                throw new \Exception('invalid combination of value and stepping notation');
            }

            $registers[$index][intval($element)] = true;
        } else {
            // asterisk indicates full range of values
            if ($element === '*') {
                $element = sprintf('%d-%d', $minv[$index], $maxv[$index]);
            }

            // range of values, e.g. "9-17"
            if (strpos($element, '-') !== false) {
                if (sizeof($ranges = explode('-', $element)) !== 2) {
                    throw new \Exception('invalid range notation');
                }

                // validate range
                foreach ($ranges as $range) {
                    if (is_numeric($range)) {
                        if (intval($range) < $minv[$index] || intval($range) > $maxv[$index]) {
                            throw new \Exception('invalid range start or end value');
                        }
                    } else {
                        throw new \Exception('non-numeric range notation');
                    }
                }

                // fill matching register
                if ($ranges[0] === $ranges[1]) {
                    $registers[$index][$ranges[0]] = true;
                } else {
                    for ($i = $minv[$index]; $i <= $maxv[$index]; $i++) {
                        if (($i - $ranges[0]) % $stepping === 0) {
                            if ($ranges[0] < $ranges[1]) {
                                if ($i >= $ranges[0] && $i <= $ranges[1]) {
                                    $registers[$index][$i] = true;
                                }
                            } elseif ($i >= $ranges[0] || $i <= $ranges[1]) {
                                $registers[$index][$i] = true;
                            }
                        }
                    }
                }
            } else {
                throw new \Exception('failed to parse list segment');
            }
        }
    }
}
