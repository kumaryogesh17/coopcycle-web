<?php

namespace AppBundle\DataType;

use AppBundle\Entity\Task;
use AppBundle\DataType\RoutingProblem\Job;
use AppBundle\DataType\RoutingProblem\Vehicle;

/**
 * a RoutingProblem represents a set of tasks and vehicles
 *
 * @see https://github.com/VROOM-Project/vroom/blob/master/docs/API.md
 */
class RoutingProblem
{
    private $jobs = [];
    private $vehicles = [];

    public function getJobs(): array
    {
        return $this->jobs;
    }

    public function addJob(Job $job)
    {
        $this->jobs[] = $job;
    }

    public function getVehicles(): array
    {
        return $this->vehicles;
    }

    public function addVehicle(Vehicle $vehicle)
    {
        $this->vehicles[] = $vehicle;
    }
}
