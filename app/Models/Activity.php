<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Models\Activity as BaseActivity;

class Activity extends BaseActivity
{
    use HasFactory;

    protected $table = "activity_log";

    protected $appends = ['icon', 'link', 'subject_name', 'row_changes', 'name'];

    public function getNameAttribute()
    {
        return !empty($this->attributes['log_name']) ? __terms($this->attributes['log_name']) : '';
    }

    public function getEventAttribute($value)
    {
        return !empty($value) ? __($value) : '';
    }

    public function getActivityDataAttribute()
    {
        return [

            'User' => [
                'icon' => '<i class="bi bi-people text-primary fs-1"></i>',
                'link' => url('/users/?showId='),
            ],
            'Role' => [
                'icon' => '<i class="ki-solid ki-profile-user text-primary fs-1"></i>',
                'link' => url('/roles/?showId='),
            ],
            'Work Team Member' => [
                'icon' => '<i class="bi bi-person-badge text-primary fs-1"></i>',
                'link' => url('/organizational-structure/work-team-members/?showId='),
            ],
            'Strategic Plan' => [
                'icon' => '<i class="bi bi-clipboard-data text-primary fs-1"></i>',
                'link' => url('/strategic/strategic-plans/?showId='),
            ],
            'Operational Plan' => [
                'icon' => '<i class="bi bi-gear-wide text-primary fs-1"></i>',
                'link' => url('/operational/operational-plans/?showId='),
            ],
            'Strategic Structure' => [
                'icon' => '<i class="bi bi-bullseye text-primary fs-1"></i>',
                'link' => url('/strategic/strategic-structure/?showId='),
            ],
            'Operational Structure' => [
                'icon' => '<i class="bi bi-diagram-3 text-primary fs-1"></i>',
                'link' => url('/operational/operational-structure/?showId='),
            ],
            'Job' => [
                'icon' => '<i class="bi bi-file-earmark-person text-primary fs-1"></i>',
                'link' => url('/organizational-structure/jobs/?showId='),
            ],
            'Section' => [
                'icon' => '<i class="bi bi-building text-primary fs-1"></i>',
                'link' => url('/organizational-structure/sections/?showId='),
            ],
            'KPI Status' => [
                'icon' => '<i class="bi bi-graph-up text-primary fs-1"></i>',
                'link' => url('/kpi/kpi-statuses/?showId='),
            ],
            'Value' => [
                'icon' => '<i class="bi bi-gem text-primary fs-1"></i>',
                'link' => url('/strategic/values/?showId='),
            ],
            'Custom Field' => [
                'icon' => '<i class="bi bi-input-cursor text-primary fs-1"></i>',
                'link' => url('/custom-fields/?showId='),
                'mainField' => 'label',
            ],
            'Task' => [
                'icon' => '<i class="bi bi-check2-square text-primary fs-2"></i>',
                'link' => url('/tasks/?task_id='),
            ],
            'Tasks Types' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-2"></i>',
                'link' => url('/tasks/task-types'),
            ],
            'Tasks Status' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-2"></i>',
                'link' => url('/tasks/statuses'),
            ],
            'Task Comment' => [
                'icon' => '<i class="bi bi-chat-left-text text-primary fs-2"></i>',
                'link' => url('/tasks/comment/?showId='),
                'mainField' => 'comment',
            ],
            'Reference' => [
                'icon' => '<i class="bi bi-paperclip text-primary fs-1"></i>',
                'link' => url('/tasks'),
                'mainField' => 'file_name',
            ],
            'Result' => [
                'icon' => '<i class="bi bi-trophy text-primary fs-1"></i>',
                'link' => url('/tasks'),
                'mainField' => 'file_name',
            ],
            'Portfolio' => [
                'icon' => '<i class="ki-duotone ki-wallet fs-1"></i>',
                'link' => url('/projects/portfolios/?showId='),
            ],
            'Risk Status' => [
                'icon' => '<i class="bi bi-heart-pulse text-primary fs-1"></i>',
                'link' => url('/risks/risk-statuses/?showId='),
            ],
            'Project Status' => [
                'icon' => '<i class="bi bi-pie-chart text-primary  fs-1"></i>',
                'link' => url('/projects/projects-statuses/?showId='),
            ],
            'Project' => [
                'icon' => '<i class="bi bi-briefcase text-primary  fs-1"></i>',
                'link' => url('/projects/?project_id='),
            ],
            'Project Charter' => [
                'icon' => '<i class="bi bi-file-earmark-ruled text-primary  fs-1"></i>',
                'link' => url('/projects/projects-charters/?showId='),
            ],
            'Portfolio Request' => [
                'icon' => '<i class="bi bi-inbox text-primary  fs-1"></i>',
                'link' => url('/projects/portfolios/?showId='),
                'mainField' => 'creation_source',
            ],
            'Risk Register' => [
                'icon' => '<i class="bi bi-journal-medical text-primary fs-1"></i>',
                'link' => url('/risks/risk-registers/?showId='),
            ],
            'Risk' => [
                'icon' => '<i class="bi bi-exclamation-triangle-fill text-danger fs-1"></i>',
                'link' => url('/risks/risks/?showId='),
            ],
            'Risk Action' => [
                'icon' => '<i class="bi bi-lightning text-danger fs-1"></i>',
                'link' => url('/risks/risk-actions/?showId='),
                'mainField' => 'title',
            ],
            'Project Comment' => [
                'icon' => '<i class="bi bi-chat-text text-primary fs-2"></i>',
                'link' => url('/projects'),
                'mainField' => 'comment',
            ],
            'Project Comment Like' => [
                'icon' => '<i class="bi bi-hand-thumbs-up text-primary fs-2"></i>',
                'link' => url('/projects'),
            ],
            'Project Expense' => [
                'icon' => '<i class="bi bi-cash-coin fs-1 text-primary fs-2"></i>',
                'link' => url('/projects/?showId='),
                'mainField' => 'item',
            ],
            'Project Budget Category' => [
                'icon' => '<i class="bi bi-piggy-bank fs-1 text-primary fs-2"></i>',
                'link' => url('/projects/?showId='),
            ],
            'Project Quality Criteria' => [
                'icon' => '<i class="bi bi-patch-check text-success fs-2"></i>',
                'link' => url('/projects/project-quality/?showId='),
                'mainField' => 'criteria',
            ],
            'Projects Change Request' => [
                'icon' => '<i class="bi bi-arrow-repeat text-success fs-2"></i>',
                'link' => url('projects/projects-change-requests/?showId='),
                'mainField' => 'title',
            ],
            'Project Scope' => [
                'icon' => '<i class="bi bi-arrows-fullscreen text-success fs-2"></i>',
                'link' => url('/projects/?showId='),
            ],
            'Numerical Qualitative Statistic' => [
                'icon' => '<i class="bi bi-bar-chart-line text-primary fs-1"></i>',
                'link' => url('/analysis/numerical-qualitative-statistics/?showId='),
            ],
            'Swot Side' => [
                'icon' => '<i class="bi bi-grid-1x2 text-primary fs-1"></i>',
                'link' => url('/analysis/manage-swot/?showId='),
            ],
            'Swot Element' => [
                'icon' => '<i class="bi bi-card-text text-primary fs-1"></i>',
                'link' => url('/analysis/manage-swot/?showId='),
            ],
            'Swot Strategy' => [
                'icon' => '<i class="bi bi-lightbulb text-primary fs-1"></i>',
                'link' => url('/analysis/manage-swot/?showId='),
                'mainField' => 'benefit',
            ],
            'Kpi' => [
                'icon' => '<i class="bi bi-speedometer2 text-primary fs-1"></i>',
                'link' => url('/kpi?kpi_id='),
            ],
            'Job Type' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/organizational-structure/job-types/?showId='),
            ],
            'Initiative Template' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/initiatives/initiative-templates/?showId='),
            ],
            'Initiative Template Tab' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',

                'link' => url('/initiatives/initiative-templates/?showId='),
            ],
            'Initiative Template Field' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/initiatives/initiative-templates/?showId='),
            ],
            'Initiative Design' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/initiatives/initiative-designs/?showId='),
            ],
            'Council Role' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/council-and-committee/council-roles/?showId='),
            ],
            'Council Type' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/council-and-committee/council-types/?showId='),
            ],
            'Council' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/council-and-committee/councils/?showId='),
            ],
            'Initiatives Status' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/initiatives/statuses/?showId='),
            ],
            'Initiative' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/initiatives/?showId='),
            ],
            'Initiative Expense' => [
                'icon' => '<i class="bi bi-cash-coin fs-1 text-primary fs-2"></i>',
                'link' => url('/initiatives/?showId='),
                'mainField' => 'item',
            ],
            'Initiative Budget Category' => [
                'icon' => '<i class="bi bi-piggy-bank fs-1 text-primary fs-2"></i>',
                'link' => url('/initiatives/?showId='),
                'mainField' => 'name',
            ],
            'Council Task' => [
                'icon' => '<i class="bi bi-list-check text-primary fs-1"></i>',
                'link' => url('/council-and-committee/councils/?showId='),
            ],
            'Meeting' => [
                'icon' => '<i class="bi bi-calendar-event text-primary fs-1"></i>',
                'link' => url('/meetings/?showId='),
            ],
            'AutomationRule' => [
                'icon' => '<i class="bi bi-gear text-primary fs-1"></i>',
                'link' => url('/tasks/automations'),
                'mainField' => 'name',
            ],
            'AutomationRun' => [
                'icon' => '<i class="bi bi-play-circle text-primary fs-1"></i>',
                'link' => url('/tasks/automations'),
                'mainField' => 'rule_name',
            ],
            'Workspace' => [
                'icon' => '<i class="bi bi-grid text-primary fs-1"></i>',
                'link' => url('/workspaces/?showId='),
                'mainField' => 'name',
            ],
        ];
    }

    public function getIconAttribute()
    {
        $data = $this->activityData[$this->log_name] ?? null;
        return $data['icon'] ?? '<i class="bi bi-info-circle text-primary fs-1"></i>';
    }

    public function getLinkAttribute()
    {
        $link = $this->activityData[$this->log_name]['link'] ?? null;
        if ($this->description == 'deleted' || !$link) {
            return '#';
        }

        // For Task Comment, link to the task directly
        if ($this->log_name == 'Task Comment') {
            $props = $this->getNormalizedProperties();
            $taskId = $props['attributes']['task_id'] ?? $props['old']['task_id'] ?? $this->subject?->task_id ?? null;
            if ($taskId) {
                return url('/tasks/?task_id=' . $taskId);
            }
        }

        // For AutomationRun, link to the subject task if available
        if ($this->log_name == 'AutomationRun') {
            $props = $this->getNormalizedProperties();
            $taskId = $props['attributes']['subject_task_id'] ?? $props['old']['subject_task_id'] ?? $this->subject?->subject_task_id ?? null;
            if ($taskId) {
                return url('/tasks/?task_id=' . $taskId);
            }
            return url('/tasks/automations');
        }

        return $link . $this->subject_id;
    }

    /**
     * Safely normalize properties into a clean PHP array.
     */
    public function getNormalizedProperties()
    {
        if ($this->properties instanceof \Illuminate\Support\Collection) {
            return $this->properties->toArray();
        }
        if (is_array($this->properties)) {
            return $this->properties;
        }
        if (is_string($this->properties)) {
            $decoded = json_decode($this->properties, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function getSubjectNameAttribute()
    {
        $properties = $this->getNormalizedProperties();
        $data = !empty($this->log_name) ? ($this->activityData[$this->log_name] ?? null) : null;
        $mainField = $data['mainField'] ?? null;

        $subjectName = null;
        $fieldsToCheck = array_values(array_unique(array_filter([
            $mainField,
            'name',
            'title',
            'rule_name',
            'label',
            'subject',
            'comment',
            'criteria',
            'item',
            'benefit',
            'file_name',
            'creation_source',
            'ar_name',
            'name_ar',
            'title_ar',
            'description',
        ])));

        // 1. Try to extract from activity properties (attributes or old)
        if (!empty($properties)) {
            foreach (['attributes', 'old'] as $bucket) {
                if (isset($properties[$bucket]) && is_array($properties[$bucket])) {
                    foreach ($fieldsToCheck as $field) {
                        if (!empty($properties[$bucket][$field]) && is_string($properties[$bucket][$field])) {
                            $subjectName = $properties[$bucket][$field];
                            break 2;
                        }
                    }
                }
            }
        }

        // 2. If not found in properties (e.g., status/progress updated without changing name),
        // fallback to subject relation or direct model lookup.
        if (empty($subjectName)) {
            $subject = $this->subject;

            if (!$subject && !empty($this->subject_type) && !empty($this->subject_id)) {
                if (class_exists($this->subject_type)) {
                    try {
                        $modelClass = $this->subject_type;
                        if (method_exists($modelClass, 'withTrashed')) {
                            $subject = $modelClass::withTrashed()->find($this->subject_id);
                        } else {
                            $subject = $modelClass::find($this->subject_id);
                        }
                    } catch (\Throwable $e) {}
                }
            }

            if ($subject) {
                if ($mainField && !empty($subject->{$mainField}) && is_string($subject->{$mainField})) {
                    $subjectName = $subject->{$mainField};
                } elseif (!empty($subject->name) && is_string($subject->name)) {
                    $subjectName = $subject->name;
                } elseif (!empty($subject->title) && is_string($subject->title)) {
                    $subjectName = $subject->title;
                } elseif (!empty($subject->rule_name) && is_string($subject->rule_name)) {
                    $subjectName = $subject->rule_name;
                } elseif ($subject instanceof \App\Models\AutomationRun) {
                    $subjectName = $subject->rule_name 
                        ?? $subject->automationRule?->name 
                        ?? ($subject->task?->name ? $subject->task->name : null)
                        ?? $subject->trigger_event;
                } elseif ($subject instanceof \App\Models\TaskComment) {
                    $subjectName = $subject->comment 
                        ?? ($subject->task?->name ? $subject->task->name : null);
                } elseif ($subject instanceof \App\Models\ProjectComment) {
                    $subjectName = $subject->comment 
                        ?? ($subject->project?->name ? $subject->project->name : null);
                } elseif ($subject instanceof \App\Models\WorkTeamMember && $subject->user) {
                    $subjectName = $subject->user->name;
                } else {
                    foreach ($fieldsToCheck as $field) {
                        if (!empty($subject->{$field}) && is_string($subject->{$field})) {
                            $subjectName = $subject->{$field};
                            break;
                        }
                    }
                }
            }
        }

        if (!empty($subjectName)) {
            $subjectName = trim(strip_tags((string) $subjectName));
            return \Illuminate\Support\Str::limit($subjectName, 60);
        }

        return '';
    }

    function isJSON($string)
    {
        return is_string($string) && is_array(json_decode($string, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
    }

    public function getRowChangesAttribute()
    {
        $properties = $this->getNormalizedProperties();
        $data = !empty($this->log_name) ? ($this->activityData[$this->log_name] ?? null) : null;
        $filter = $data['filter'] ?? null;
        if (!isset($properties['old']) || !isset($properties['attributes']) || !is_array($properties['old']) || !is_array($properties['attributes'])) {
            return [];
        }
        $data = [];
        $ignoredKeys = ['updated_at', 'created_at', 'deleted_at', 'translations', 'password', 'remember_token', 'token', 'id'];

        foreach ($properties['old'] as $key => $value) {
            if (in_array($key, $ignoredKeys) || str_contains($key, '_id') || str_contains($key, 'token') || $key === 'password') {
                continue;
            }
            if (!isset($properties['attributes'][$key])) {
                continue;
            }
            if ($value == $properties['attributes'][$key]) {
                continue;
            }

            if ($this->isJSON($value) || is_array($value) || is_array($properties['attributes'][$key])) {
                continue;
            }

            if (str_starts_with($key, 'is_')) {
                continue;
            }

            $filterColumn = $filter[$key] ?? null;

            $old = is_bool($value) ? ($value ? __('Yes') : __('No')) : (string) $value;
            $new = is_bool($properties['attributes'][$key]) ? ($properties['attributes'][$key] ? __('Yes') : __('No')) : (string) $properties['attributes'][$key];

            if ($filterColumn) {
                if (!is_array($new)) {
                    $new = json_decode($new, true);
                }
                $new = collect($new);
                $new = $new->map(function ($item) use ($filterColumn) {
                    if ($filterColumn) {
                        $item = collect($item)->filter(function ($value, $key) use ($filterColumn) {
                            $filterColumn = explode(',', $filterColumn);
                            return in_array($key, $filterColumn);
                        })->toArray();
                    }
                    return implode(' | ', $item);
                })->toArray();
                $new = implode(',', $new);
            } else {
                $new = __((string) $new, app('sharedData')->terms_list ?? []);
            }

            if ($filterColumn) {
                if (!is_array($old)) {
                    $old = json_decode($old, true);
                }
                $old = collect($old);
                $old = $old->map(function ($item) use ($filterColumn) {
                    if ($filterColumn) {
                        $item = collect($item)->filter(function ($value, $key) use ($filterColumn) {
                            $filterColumn = explode(',', $filterColumn);
                            return in_array($key, $filterColumn);
                        })->toArray();
                    }
                    return implode(' | ', $item);
                })->toArray();
                $old = implode(',', $old);
            } else {
                $old = __((string) $old, app('sharedData')->terms_list ?? []);
            }

            $keyClean = str_replace(['taskStatus.name', 'task_status.name', 'taskStatus.slug'], 'Task Status', $key);
            $keyClean = str_replace(['.', '_'], ' ', $keyClean);
            $newKey = __(trim(ucwords($keyClean)), app('sharedData')->terms_list ?? []);

            $oldText = trim($old);
            $newText = trim($new);

            if ($oldText === '' && $newText === '') {
                continue;
            }

            $data[$newKey] = __('From') . " ( " . ($oldText !== '' ? $oldText : __('None')) . " ) " . __('To') . " ( " . ($newText !== '' ? $newText : __('None')) . " )";
        }
        return $data;
    }
}
