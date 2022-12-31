<?php

namespace App\Model;

class User extends Model
{
    // assuming the essentials data and properties are present we can write query related logic of User in User Model so things go inclined

    // added relation to get user languages instead of querying from model
    public function userLanguages()
    {
        return $this->hasMany(UserLanguages::class, 'user_id');
    }

    public function getPotentialJobIdsWithUserId()
    {
        $eagerLoadedModel = $this->load('userMeta', 'userLanguages');

        $jobType = match ($eagerLoadedModel->userMeta->translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid',
        };

        // naming to a more specific meaning
        $userlanguageIds = collect($eagerLoadedModel->userLanguages)->pluck('lang_id')->all();

        // removing extra variables
        //! no definition for getJobs so in this we will be returning job models instead of ides to avoid extra queries
        $jobs = Job::getJobs(
            $eagerLoadedModel->id,
            $jobType,
            'pending',
            $userlanguageIds,
            $eagerLoadedModel->userMeta->gender,
            $eagerLoadedModel->userMeta->translator_level
        );

        foreach ($jobs as $key => $job)     // checking translator town
        {
            $checktown = Job::checkTowns($job->user_id, $this->id);

            if (($job->customer_phone_type == 'no' || filled($job->customer_phone_type))
                && $job->customer_physical_type == 'yes' && $checktown == false
            ) {
                $jobs->forget($key);
            }
        }

        return $jobs;
    }

    protected function isNeedToDelayPush()
    {
        if (!DateTimeHelper::isNightTime()) return false;

        return $this->not_get_nighttime == 'yes' ? true : false;
    }

    protected function isNeedToSendPush()
    {
        return $this->get_push_notifications == 'yes' ? true : false;
    }

    protected function sendTranslatorNotificationTranslatorArray(array $data, int $excludeUserId): bool
    {
        // fixing naming convention
        if (
            $this->user_type == '2'
            && $this->status == '1'
            && $this->id != $excludeUserId
            && !$this->not_get_notification
            && !($data['immediate'] == 'yes' && $this->not_get_emergency == 'yes')
        ) {
            $jobs = $this->getPotentialJobIdsWithUserId(); // get all potential jobs of this user

            foreach ($jobs as $job) {
                // one potential job is the same with current job
                $job_for_translator = Job::assignedToPaticularTranslator($this->id, $job->id);

                if ($job_for_translator == 'SpecificJob') {
                    $job_checker = Job::checkParticularJob($this->id, $job);

                    if (($job_checker != 'userCanNotAcceptJob')) {
                        if ($this->isNeedToDelayPush()) {
                            return false;
                        } else {
                            return true;
                        }
                    }
                }
            }
            return true;
        }
    }
}
