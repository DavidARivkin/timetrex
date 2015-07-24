<?php

require_once 'BaseMapper.php';
require_once 'MnoIdMap.php';

/**
* Map Connec EmployeeSalary representation to/from TimeTrex UserWage
*/
class EmployeeSalaryMapper extends BaseMapper {
  private $employee_id = null;

  public function __construct($employee_id=null) {
    parent::__construct();

    $this->connec_entity_name = 'EmployeeSalary';
    $this->local_entity_name = 'UserWage';
    $this->connec_resource_name = 'employees';
    $this->connec_resource_endpoint = 'employees/:employee_id/employee_salaries';

    $this->employee_id = $employee_id;
  }

  public function getId($employee_salary) {
    return $employee_salary->getId();
  }

  // Find by local id
  public function loadModelById($local_id) {
    $ulf = new UserWageListFactory();
    $ulf->getById($local_id);
    return $ulf->getCurrent();
  }

  // Map the Connec resource attributes onto the TimeTrex UserWage
  protected function mapConnecResourceToModel($employee_salary_hash, $employee_salary) {
    // Map hash attributes to UserWage

    $employee_salary->setUser($this->employee_id);
    $employee_salary->setWageGroup(0);
    $employee_salary->setLaborBurdenPercent(0.0);

    if($this->is_set($employee_salary_hash['hourly_rate'])) { $employee_salary->setHourlyRate($employee_salary_hash['hourly_rate']); }
    if($this->is_set($employee_salary_hash['annual_salary'])) { $employee_salary->setWage($employee_salary_hash['annual_salary']); }
    if($this->is_set($employee_salary_hash['hours_per_week'])) { $employee_salary->setWeeklyTime($employee_salary_hash['hours_per_week'] * 60 * 60); }

    if($this->is_set($employee_salary_hash['type'])) {
      if($employee_salary_hash['type'] == 'SALARY') {
        $employee_salary->setType(20);
      } else if($employee_salary_hash['type'] == 'MONTHLY') {
        $employee_salary->setType(15);
      } else if($employee_salary_hash['type'] == 'HOURLY') {
        $employee_salary->setType(10);
      }
    }
  }

  // Map the TimeTrex UserWage to a Connec resource hash
  protected function mapModelToConnecResource($employee_salary) {
    $employee_salary_hash = array();

    if($employee_salary->getHourlyRate()) { $employee_salary_hash['hourly_rate'] = $employee_salary->getHourlyRate(); }
    if($employee_salary->getWage()) { $employee_salary_hash['annual_salary'] = $employee_salary->getWage(); }
    if($employee_salary->getWeeklyTime()) { $employee_salary_hash['hours_per_week'] = $employee_salary->getWeeklyTime() / 60 / 60; }
    
    if($employee_salary->getType() == 10) {
      $employee_salary_hash['type'] = 'HOURLY';
    } else if($employee_salary->getType() == 15) {
      $employee_salary_hash['type'] = 'MONTHLY';
    } else if($employee_salary->getType() == 20) {
      $employee_salary_hash['type'] = 'SALARY';
    }

    return $employee_salary_hash;
  }

  // Persist the TimeTrex UserWage
  protected function persistLocalModel($employee_salary, $resource_hash) {
    if($employee_salary->isValid()) {
      $employee_salary->Save(false, false, false);
    } else {
      error_log("cannot save entity_name=$this->connec_entity_name, entity_id=" . $resource_hash['id'] . ", error=" . $employee_salary->Validator->getTextErrors());
    }
  }
}
