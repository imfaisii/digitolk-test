<?php

namespace DTApi\Http\Controllers;

use App\Traits\Jsonify;
use DTApi\Models\Job;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    use Jsonify;

    protected $repository;

    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    public function index(Request $request)
    {
        $data = $request->has('user_id')
            ? $this->repository->getUsersJobs($request->user_id)
            : (in_array(auth()->user()->user_type, [RolesEnum::ADMIN_ROLE_ID, RolesEnum::SUPER_ADMIN_ROLE_ID])
                ? $this->repository->getAll($request)
                : []);

        return self::success(data: $data, message: 'Jobs fetched successfully.');
    }

    public function show(Job $job)
    {
        return self::success(data: $job->load('translatorJobRel.user'), message: 'Jobs details loaded successfully.');
    }

    public function store(StoreJobRequest $request)
    {
        try {
            $job = $this->repository->store($request->validated(), auth()->user()->load('userMeta'));

            return self::success(data: $job, message: 'Job was created successfully.');
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function update(UpdateJobRequest $request, Job $job,)
    {
        // using route model binding
        try {
            $response = $this->repository->updateJob($job->load('translatorJobRel'), $request->validated(), auth()->user());

            return self::success(data: $response, message: 'Job was updated successfully.');
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function immediateJobEmail(ImmediateJobEmailRequest $request)
    {
        try {
            $response = $this->repository->storeJobEmail($request->validated());

            return self::success(data: $response, message: "Action successful.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function getHistory(Request $request)
    {
        try {
            $response = $request->safe()->has('user_id')
                ? $this->repository->getUsersJobsHistory($user_id, $request)
                : null;

            return self::success(data: $response, message: "History Fetched successfully.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function acceptJob(Job $job)
    {
        try {
            $response = $this->repository->acceptJob($job->load('user'), auth()->user());

            return self::success(data: $response, message: "Job was accepted successfully.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function cancelJob(Request $request)
    {
        try {
            $response = $this->repository->cancelJobAjax($request->validated(), auth()->user());

            return self::success(data: $response, message: "Job was canceled successfully.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function endJob(EndJobRequest $request)
    {
        try {
            $response = $this->repository->endJob($request->validated());

            return self::success(data: $response, message: "Job was ended successfully.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function customerNotCall(CustomerNotCallRequest $request)
    {
        try {
            $response = $this->repository->customerNotCall($request->validated());

            return self::success(data: $response, message: "Job was ended successfully.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function getPotentialJobs(Request $request)
    {
        try {
            $response = $this->repository->getPotentialJobs(auth()->user());

            return self::success(data: $response, message: "Potential jobs weere fetched successfully.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function distanceFeed(DistanceFeedRequest $request, Job $job)
    {
        try {
            // only updating the validated data
            $job->update($request->validated());

            return self::success(data: [], message: "Distance feed updated successfully.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function reopen(ReopenJobRequest $request, Job $job)
    {
        try {
            $response = $this->repository->reopen($job, $request->validated());

            return self::success(data: $response, message: "Reopen successfully.");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function resendNotifications(ResendNoticationRequest $request, Job $job)
    {
        try {
            $jobData = $this->repository->jobToData($job);

            $this->repository->sendNotificationTranslator($job, $jobData, '*');

            return self::success(message: "PUSH Sent");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }

    public function resendSMSNotifications(Request $request, Job $job)
    {
        try {
            $this->repository->sendSMSNotificationToTranslator($job);

            return self::success(message: "SMS Sent");
        } catch (\Exception $e) {
            return self::error(message: $e->getMessage(), code: $e->getCode());
        }
    }
}
