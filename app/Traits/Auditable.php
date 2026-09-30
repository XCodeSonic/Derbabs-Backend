<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;

trait Auditable
{
    /**
     * Safe log method - never throws exceptions
     */
    protected function logAction($action, $module, $recordId = null, $oldValues = null, $newValues = null, $description = null)
    {
        return AuditLog::log($action, $module, $recordId, $oldValues, $newValues, $description);
    }

    /**
     * Log a create action
     */
    protected function logCreate($model, $module = null, $description = null)
    {
        return AuditLog::log(
            AuditLog::ACTION_CREATE,
            $module ?? $model->getTable(),
            $model->getKey(),
            null,
            $model->toArray(),
            $description ?? "Created {$model->getTable()} record"
        );
    }

    /**
     * Log an update action with changed values
     */
    protected function logUpdate($model, $module = null, $oldValues = null, $description = null)
    {
        try {
            $newValues = $model->toArray();
            $oldData = $oldValues ?? $model->getOriginal();
            
            // Only log if there are actual changes
            $changed = [];
            foreach ($newValues as $key => $value) {
                if (isset($oldData[$key]) && $oldData[$key] != $value) {
                    $changed[$key] = ['old' => $oldData[$key], 'new' => $value];
                }
            }
            
            if (empty($changed)) {
                return null;
            }
            
            return AuditLog::log(
                AuditLog::ACTION_UPDATE,
                $module ?? $model->getTable(),
                $model->getKey(),
                $oldData,
                $newValues,
                $description ?? "Updated {$model->getTable()} record"
            );
        } catch (\Exception $e) {
            Log::warning('Failed to log update: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Log a delete action
     */
    protected function logDelete($model, $module = null, $description = null)
    {
        return AuditLog::log(
            AuditLog::ACTION_DELETE,
            $module ?? $model->getTable(),
            $model->getKey(),
            $model->toArray(),
            null,
            $description ?? "Deleted {$model->getTable()} record"
        );
    }

    /**
     * Log a custom action
     */
    protected function logCustom($action, $module, $recordId = null, $description = null, $data = null)
    {
        return AuditLog::log(
            $action,
            $module,
            $recordId,
            null,
            is_array($data) ? $data : ['data' => $data],
            $description
        );
    }
}