<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

final class LineVehicleValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'integer|gt:0',
        'version' => 'integer|gt:0',
        'name' => 'require|max:120',
        'handoff_location' => 'require|max:255',
        'departure_time' => 'require|max:5',
        'buffer_minutes' => 'require|integer|egt:0|elt:1440',
        'is_enabled' => 'in:0,1',
        'trip_id' => 'require|integer|gt:0',
        'trip_report_id' => 'require|integer|gt:0',
        'trip_date' => 'max:10',
        'planned_store_departure_time' => 'max:19',
        'actual_store_departure_time' => 'max:19',
        'actual_handoff_time' => 'max:19',
        'actual_handoff_packages' => 'integer|gt:0',
        'actual_package_count' => 'integer|egt:0',
        'stage' => 'in:packed,loaded',
        'exception_note' => 'max:500',
        'exception_reason' => 'max:500',
        'second_confirmed' => 'in:0,1',
        'idempotency_key' => 'max:96',
        'reports' => 'require|array|min:1',
        'items' => 'array|min:1',
        'reroute_method' => 'require|in:customer_vehicle,third_party,self_delivery',
        'reroute_reason' => 'require|max:500',
        'return_reason' => 'require|max:500',
        'status' => 'in:planned,loading,departed,completed,closed',
    ];

    public function sceneScheduleSave() { return $this->only(['id', 'version', 'name', 'handoff_location', 'departure_time', 'buffer_minutes', 'is_enabled']); }
    public function sceneSchedules() { return $this->only(['is_enabled']); }
    public function sceneTripCreate() { return $this->only(['trip_date', 'planned_store_departure_time', 'idempotency_key', 'reports']); }
    public function sceneTrips() { return $this->only(['trip_date', 'status']); }
    public function sceneTripDetail() { return $this->only(['trip_id']); }
    public function scenePackageRecord() { return $this->only(['trip_report_id', 'stage', 'actual_package_count', 'exception_note']); }
    public function sceneTripDepart() { return $this->only(['trip_id', 'actual_store_departure_time']); }
    public function sceneReroute() { return $this->only(['trip_report_id', 'reroute_method', 'reroute_reason']); }
    public function sceneReturnPending() { return $this->only(['trip_report_id', 'return_reason', 'idempotency_key']); }
    public function sceneHandoffConfirm() { return $this->only(['trip_report_id', 'actual_handoff_packages', 'actual_handoff_time', 'items', 'exception_note', 'exception_reason', 'second_confirmed', 'idempotency_key']); }
    public function sceneManifest() { return $this->only(['trip_id']); }
}
