<?php

namespace Spatie\Activitylog;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use Spatie\Activitylog\Contracts\Activity;
use Illuminate\Contracts\Config\Repository;
use Spatie\Activitylog\Exceptions\CouldNotLogActivity;

class ActivityLogger
{
    use Macroable;

    /** @var \Illuminate\Auth\AuthManager */
    protected $auth;

    protected $logName = '';

    /** @var \Illuminate\Database\Eloquent\Model */
    protected $performedOn;

    /** @var \Illuminate\Database\Eloquent\Model */
    protected $causedBy;

    /** @var \Illuminate\Support\Collection */
    protected $properties;

    /** @var string */
    protected $authDriver;

    /** @var \Spatie\Activitylog\ActivityLogStatus */
    protected $logStatus;

    /**
     * Contragent model
     *
     * @var
     */
    protected $contragent;

    public function __construct(AuthManager $auth, Repository $config, ActivityLogStatus $logStatus)
    {
        $this->auth = $auth;

        $this->properties = collect();

        $this->authDriver = $config['activitylog']['default_auth_driver'] ?? $auth->getDefaultDriver();

        if (starts_with(app()->version(), '5.1')) {
            $this->causedBy = $auth->driver($this->authDriver)->user();
        } else {
            $this->causedBy = $auth->guard($this->authDriver)->user();
        }

        $this->logName = $config['activitylog']['default_log_name'];

        $this->logEnabled = $config['activitylog']['enabled'] ?? true;

        $this->logStatus = $logStatus;
    }

    public function setLogStatus(ActivityLogStatus $logStatus)
    {
        $this->logStatus = $logStatus;

        return $this;
    }

    /**
     * @param Model $contragent
     * @return ActivityLogger
     */
    public function withContragent($contragent)
    {
        if ($contragent instanceof Model) {
            $this->contragent = $contragent;
        } elseif ($contragent instanceof Collection) {
            if ($contragent->count() > 0) {
                $this->contragent = $contragent;
            }
        } elseif (is_array($contragent)) {
            if (count($contragent) > 0) {
                $this->contragent = collect($contragent);
            }
        }

        return $this;
    }

    /**
     * @param $contragent
     * @return ActivityLogger
     */
    public function withContragents($contragent)
    {
        return $this->withContragent($contragent);
    }

    public function performedOn(Model $model)
    {
        $this->performedOn = $model;

        return $this;
    }

    public function on(Model $model)
    {
        return $this->performedOn($model);
    }

    public function causedBy($modelOrId)
    {
        if ($modelOrId === null) {
            return $this;
        }

        $model = $this->normalizeCauser($modelOrId);

        $this->causedBy = $model;

        return $this;
    }

    public function by($modelOrId)
    {
        return $this->causedBy($modelOrId);
    }

    public function withProperties($properties)
    {
        $this->properties = collect($properties);

        return $this;
    }

    public function withProperty(string $key, $value)
    {
        $this->properties->put($key, $value);

        return $this;
    }

    public function useLog(string $logName)
    {
        $this->logName = $logName;

        return $this;
    }

    public function inLog(string $logName)
    {
        return $this->useLog($logName);
    }

    public function enableLogging()
    {
        $this->logStatus->enable();

        return $this;
    }

    public function disableLogging()
    {
        $this->logStatus->disable();

        return $this;
    }

    /**
     * @param string $description
     * @return Model
     */
    protected function logOne(string $description): Model
    {
        $activity = ActivitylogServiceProvider::getActivityModelInstance();

        if ($this->performedOn) {
            $activity->subject()->associate($this->performedOn);
        }

        if ($this->causedBy) {
            $activity->causer()->associate($this->causedBy);
        }

        $activity->properties = $this->properties;
        $activity->description = $this->replacePlaceholders($description, $activity);
        $activity->log_name = $this->logName;
        $activity->ip_address = geoip()->getClientIP();
        $activity->save();

        return $activity;
    }

    public function log(string $description)
    {
        if ($this->logStatus->disabled()) {
            return;
        }

        $activities = collect();

        if ($this->contragent) {
            if ($this->contragent instanceof Collection) {
                foreach ($this->contragent as $contragent) {
                    $activity = $this->logOne($description);
                    $activity->activityLoggable()->save($contragent);
                    $activities->push($activity);
                }
            } else {
                $activity = $this->logOne($description);
                $activity->activityLoggable()->save($this->contragent);
                $activities->push($activity);
            }
        } else {
            $activity = $this->logOne($description);
            $activities->push($activity);
        }

        return $activities;
    }

    protected function normalizeCauser($modelOrId): Model
    {
        if ($modelOrId instanceof Model) {
            return $modelOrId;
        }

        if (starts_with(app()->version(), '5.1')) {
            $model = $this->auth->driver($this->authDriver)->getProvider()->retrieveById($modelOrId);
        } else {
            $model = $this->auth->guard($this->authDriver)->getProvider()->retrieveById($modelOrId);
        }

        if ($model) {
            return $model;
        }

        throw CouldNotLogActivity::couldNotDetermineUser($modelOrId);
    }

    protected function replacePlaceholders(string $description, Activity $activity): string
    {
        return preg_replace_callback('/:[a-z0-9._-]+/i', function ($match) use ($activity) {
            $match = $match[0];

            $attribute = (string) string($match)->between(':', '.');

            if (! in_array($attribute, ['subject', 'causer', 'properties'])) {
                return $match;
            }

            $propertyName = substr($match, strpos($match, '.') + 1);

            $attributeValue = $activity->$attribute;

            if (is_null($attributeValue)) {
                return $match;
            }

            $attributeValue = $attributeValue->toArray();

            return array_get($attributeValue, $propertyName, $match);
        }, $description);
    }
}
