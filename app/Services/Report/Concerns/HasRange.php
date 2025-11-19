<?php

namespace FluentCart\App\Services\Report\Concerns;

use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ReportService;
use FluentCart\Framework\Support\Arr;

trait HasRange
{
    protected string $rangeKey = 'created_at';
    protected ?\DateTimeZone $originalTimeZone = null;

    public function getRangeKey(): string
    {
        return $this->rangeKey;
    }

    /**
     * @param string $rangeKey
     * @return ReportService
     */
    public function setRangeKey(string $rangeKey)
    {
        $this->rangeKey = $rangeKey;
        return $this;
    }

    public function getRange(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date'   => $this->endDate,
        ];
    }

    /**
     * @param $startDate
     * @param $endDate
     */
    public function setRange($startDate, $endDate, bool $handleDayClosingTime = true)
    {
        if ($handleDayClosingTime) {
            $endDate = DateTime::anyTimeToGmt($endDate)->endOfDay();
        }
        return $this->setStartDate($startDate)->setEndDate($endDate);
    }

    /**
     * @param $year
     */
    public function setYearlyRange($selectedYear)
    {

        $startDate = DateTime::parse("{$selectedYear}-01-01")->startOfYear();
        $endDate = DateTime::parse("{$selectedYear}-01-01")->endOfYear();

        return $this->setStartDate($startDate)->setEndDate($endDate);
    }

    protected ?DateTime $startDate = null;

    /**
     * @return mixed
     */
    public function getStartDate(): ?DateTime
    {
        return $this->startDate;
    }

    /**
     * @param mixed $startDate
     */
    public function setStartDate($startDate)
    {
        $this->startDate = $this->validate($startDate);
        return $this;
    }

    protected ?DateTime $endDate = null;

    /**
     * @return mixed
     */
    public function getEndDate(): ?DateTime
    {
        return $this->startDate;
    }

    /**
     * @param null $endDate
     */
    public function setEndDate($endDate)
    {
        $this->endDate = $this->validate($endDate);
        return $this;
    }

    protected function validate($time)
    {
        if ($this->originalTimeZone === null) {
            $this->originalTimeZone = DateTime::extractTimezone($time);
        }
        return DateTime::anyTimeToGmt($time);
    }

    protected function getFilters(): array
    {

        $filters = $this->filters;

        $filterRangeValues = Arr::get($filters, $this->getRangeKey() . '.value');

        if (!is_array($filterRangeValues)) {
            $filterRangeValues = [];
        }

        if (!empty($this->startDate) && !empty($this->endDate)) {
            $filterRangeValues[0] = $this->startDate;
            $filterRangeValues[1] = $this->endDate;
        }

        if (!empty($this->startDate) && !empty($this->endDate)) {
            $filters[$this->getRangeKey()] =
                [
                    "column"   => $this->getRangeKey(),
                    "operator" => "between",
                    "value"    => $filterRangeValues
                ];

        } else if (!empty($this->startDate)) {
            $filters[] = [
                "column"   => $this->getRangeKey(),
                "operator" => ">=",
                "value"    => $this->startDate
            ];
        } else if (!empty($this->endDate)) {
            $filters[] = [
                "column"   => $this->getRangeKey(),
                "operator" => "<=",
                "value"    => $this->endDate
            ];
        }

        return $filters;

    }

    public function getRangeDiffInMonth(): int
    {
        if (!empty($this->startDate) && !empty($this->endDate)) {
            (new DateTime($this->startDate))->diffInMonths(
                new DateTime($this->endDate)
            );
        }
        return 0;
    }

    public function getRangeDiffInYear(): int
    {
        if (!empty($this->startDate) && !empty($this->endDate)) {
            (new DateTime($this->startDate))->diffInYears(
                new DateTime($this->endDate)
            );
        }
        return 0;
    }

    public function test()
    {
        return 6;
    }

}