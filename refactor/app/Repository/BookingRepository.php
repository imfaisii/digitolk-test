<?php

namespace DTApi\Repository;

use App\Helpers\GlobalHelpers;
use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function getUsersJobs(User $user): array
    {
        $emergencyJobs = array();
        $noramlJobs = array();

        // using ENUMS and moving longs lines to next line to improve readability
        if ($user && $user->is(UserTypesEnum::CUSTOMER)) {
            $jobs = $user
                ->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();

            $usertype = 'customer';
        } elseif ($user && $user->is(UserTypesEnum::TRANSLATOR)) {
            $jobs = Job::getTranslatorJobs($user->id, 'new');
            $usertype = 'translator';
        }

        // no need for if, as it will only enter if count is greater than 0
        foreach ($jobs as $jobitem) {
            $jobitem->immediate == 'yes'
                ? $emergencyJobs[] = $jobitem
                : $noramlJobs[] = $jobitem;
        }

        $noramlJobs = collect($noramlJobs)->each(function ($job, $key) use ($user) {
            $item['usercheck'] = Job::checkParticularJob($user->id, $job);
        })->sortBy('due')->all();

        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $user, 'usertype' => $usertype];
    }

    public function getUsersJobsHistory(User $user, Request $request): array
    {
        $pagenum = $request->get('page', "1");
        $emergencyJobs = array();

        if ($user && $user->is(UserTypesEnum::CUSTOMER)) {
            $jobs = $user
                ->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            $usertype = 'customer';
        } elseif ($user && $user->is(UserTypesEnum::TRANSLATOR)) {
            $jobs = Job::getTranslatorJobsHistoric($user->id, 'historic', $pagenum);
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'noramlJobs' => [],
            'jobs' => $jobs,
            'cuser' => $user,
            'usertype' => $usertype,
            'numpages' => 0,
            'pagenum' => 0
        ];
    }

    public static function getErrorResponseByKeyName(string $keyName)
    {
        return [
            'status' => 'fail',
            'message' => 'Du måste fylla in alla fält',
            'field_name' => $keyName
        ];
    }

    public function store($data, User $cuser): array
    {
        // eager loaded data prevents extra queries

        $immediatetime = 5;
        $consumer_type = $cuser->userMeta->consumer_type;

        if ($cuser->user_type == RolesEnum::CUSTOMER_ROLE_ID) {
            // using filled to check isset and != '' at once

            if (!filled($data['from_language_id'])) {
                return self::getErrorResponseByKeyName('from_language_id');
            }

            // fixing if NO in uppercase
            if (Str::lower($data['immediate']) == 'no') {
                if (!filled($data['customer_phone_type'])) {
                    return self::getErrorResponseByKeyName('due_date');
                }

                if (!filled($data['due_time'])) {
                    return self::getErrorResponseByKeyName('due_time');
                }

                if (!filled($data['customer_phone_type'])) {
                    return self::getErrorResponseByKeyName('customer_phone_type');
                }

                if (!filled($data['duration'])) {
                    return self::getErrorResponseByKeyName('duration');
                }
            }

            $data['customer_phone_type'] = filled($data['customer_phone_type']) ? 'yes' : 'no';

            if (filled($data['customer_physical_type'])) {
                $data['customer_physical_type'] = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type'] = 'no';
                $response['customer_physical_type'] = 'no';
            }

            if ($data['immediate'] == 'yes') {
                $data['due'] = now()->addMinute($immediatetime)->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $data['due'] = Carbon::createFromFormat('m/d/Y H:i', $due)->format('Y-m-d H:i:s');
                if ($data['due']->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            }

            // using ternary and match ( PHP 8 ) to make code more readable and shorter
            $data['gender'] = $data['job_for'] == 'male' ? 'male' : 'female';

            $data['certified'] = match ($data['job_for']) {
                'normal' => 'normal',
                'certified' => 'yes',
                'certified_in_law' => 'law',
                'certified_in_helth' => 'health',
                default => 'normal'
            };

            // using a global helper to check multiple in_array at once
            if (GlobalHelpers::in_array_all(['normal', 'certified'], $data['job_for'])) {
                $data['certified'] = 'both';
            } else if (GlobalHelpers::in_array_all(['normal', 'certified_in_law'], $data['job_for'])) {
                $data['certified'] = 'n_law';
            } else if (GlobalHelpers::in_array_all(['normal', 'certified_in_helth'], $data['job_for'])) {
                $data['certified'] = 'n_health';
            }

            // default case not handled in older cases
            $data['job_type'] = match ($consumer_type) {
                'rwsconsumer' => 'rws',
                'ngo' => 'unpaid',
                'paid' => 'paid',
                default => 'unpaid'
            };

            $data['b_created_at'] = now();

            if (isset($due)) $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);

            // no need for ternary
            $data['by_admin'] ?? 'no';

            $job = $cuser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = array();

            // function programming
            if (!is_null($job->gender)) {
                $data['job_for'][] = $job->gender == 'male'
                    ? 'Man'
                    : 'Kvinna';
            }

            if (!is_null($job->certified)) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;

            // firing event only if job created successfully
            if ($job) {
                Event::fire(new JobWasCreated($job, $data, '*'));
                $this->sendNotificationToSuitableTranslators($job->id, $data, '*'); // send Push for New job posting
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;
    }

    public function storeJobEmail(array $data): array
    {
        $userType = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id'])->load('user.userMeta');
        $job->user_email = @$data['user_email'];
        $job->reference = $data['reference'] ?? '';

        if (isset($data['address'])) {
            $job->address = filled($data['address']) ? $data['address'] : $job->user->userMeta->address;
            $job->instructions = filled(['instructions']) ? $data['instructions'] : $job->user->userMeta->instructions;
            $job->town = filled($data['town']) ? $data['town'] : $job->user->userMeta->city;
        }

        $job->save();

        $email = filled($job->user_email) ? $job->user_email : $job->user->email;

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $job->user,
            'job'  => $job
        ];

        $this->mailer->send($email, $job->user->name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $userType;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);

        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    public function jobToData(Job $job)
    {
        // no need to assigning with the same key and attribute
        $data = $job->toArray();
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        // assigning values
        [$data['due_date'], $data['due_time']] =  explode(" ", $job->due);

        $data['job_for'] = [];

        if (!is_null($job->gender)) {
            // ternary operator makes code more readable and shorter
            $data['job_for'][] = $job->gender == 'male'
                ? 'Man'
                : 'Kvinna';
        }

        if (!is_null($job->certified)) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    public function jobEnd($postData = array())
    {
        // eager loaded and removed extra variables
        $job = Job::with(['user', 'translatorJobRel'])->find($postData["job_id"]);
        $start = date_create($job->due);
        $end = date_create(now());
        $diff = date_diff($end, $start);

        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job->end_at = now();
        $job->status = 'completed';
        $job->session_time = $interval;

        $session_explode = explode(':', $job->session_time);
        $sessionTime = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        // moved redundant code to function
        $this->notifyViaEmail($job->user, $job, $sessionTime);

        $job->save();

        $tr = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->with('user')->first();

        Event::fire(new SessionEnded($job, ($postData['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $this->notifyViaEmail($tr->user, $job, $sessionTime);

        $tr->update([
            'completed_at' => now(),
            'cancel_at'    => $postData['userid']
        ]);
    }

    public function notifyViaEmail(User $user, Job $job, $sessionTime)
    {
        $subject = "Information om avslutad tolkning för bokningsnummer # $job->id";
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($user->email, $user->name, $subject, 'emails.session-ended', $data);
    }

    public function sendNotificationTranslator(Job $job, array $data = [], int $excludeUserId)
    {
        $users = User::all();
        $delpay_translator_array = [];
        $translator_array = [];

        foreach ($users as $user) {
            // added a function to User Model
            $user->sendTranslatorNotificationTranslatorArray($data, $excludeUserId)
                ? $delpay_translator_array[] = $user
                : $translator_array[] = $user;
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = $data['immediate'] == 'no'
            ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due']
            : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

        $msgText = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo("Push send for job  $job->id, [$translator_array, $delpay_translator_array, $msgText, $data]");
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msgText, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msgText, true); // send new booking push to suitable translators(need to delay)
    }

    public function sendSMSNotificationToTranslator(Job $job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::whereBelonsTo($job->use)->first();

        // prepare message templates
        [$date, $time] = explode(" ", date(strtotime($job->due)));
        $duration = $this->convertToHoursMins($job->duration);

        $phoneJobMessageTemplate = trans('sms.phone_job', [
            'date' => $date,
            'time' => $time,
            'duration' =>
            $duration,
            'jobId' => $job->id
        ]);

        $physicalJobMessageTemplate = trans('sms.physical_job', [
            'date' => $date,
            'time' => $time,
            'town' => $job->city ?? $jobPosterMeta->city,
            'duration' => $duration,
            'jobId' => $job->id
        ]);

        $message = $job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no'
            ? $physicalJobMessageTemplate
            : ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes'
                ? $phoneJobMessageTemplate
                : '');

        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(config('sms.SMS_NUMBER'), $translator->mobile, $message);
            Log::info("Send SMS to $translator->email ($translator->mobile), status:" . print_r($status, true));
        }

        return count($translators);
    }

    public function sendPushNotificationToSpecificUsers($users, Job $job, array $data, array $msgText, bool $needDelay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());

        $logger->addInfo("Push send for job  $job_id, [$users, $data, $msgText, $is_need_delay]");

        // always use config variables for performance reasons
        if (config('app.APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $userTags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job->id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($userTags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msgText,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );

        if ($needDelay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }

        $fields = json_encode($fields);
        $response = $this->sendOneSignalNotification($onesignalRestAuthKey, $fields);
        $logger->addInfo("Push send for job $job->id curl answer", [$response]);
    }

    public function sendOneSignalNotification($onesignalRestAuthKey, $fields)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    public function getPotentialTranslators(Job $job)
    {
        $translator_type = match ($job->job_type) {
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
            default => 'volunteer'
        };

        $translator_level = [];

        if (filled($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::whereBelongsTo($job->user)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id');
        $users = User::getPotentialUsers($translator_type, $job->from_language_id, $job->gender, $translator_level, $translatorsId);

        return $users;
    }

    public function updateJob(Job $job, array $data, User $cuser)
    {
        $currentTranslator = $job->translatorJobRel->whereNull('cancel_at')->first()
            ?? $job->translatorJobRel->whereNotNull('completed_at')->first();

        $log_data = [];
        $langChanged = false;
        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);

        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);

        if ($changeDue['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'oldLang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];

            $oldLang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);

        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $oldTime);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['newTranslator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $oldLang);
        }
    }

    private function changeStatus(Job $job, array $data, Translator $changedTranslator): array
    {
        if ($job->status != $data['status']) {

            // PHP 8 match makes things very simple
            $statusChanged = match ($job->status) {
                'timedout' => $this->changeTimedoutStatus($job->load('user'), $data, $changedTranslator),
                'started' => $this->changeStartedStatus($job, $data),
                'pending' => $this->changePendingStatus($job, $data, $changedTranslator),
                'withdrawafter24' => $this->changeWithdrawafter24Status($job, $data),
                'assigned' => $this->changeAssignedStatus($job, $data),
                'completed' => $job->save(),
                default => false
            };

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $job->status,
                    'new_status' => $data['status']
                ];

                return ['statusChanged' => true, 'log_data' => $log_data];
            }
        }
        return ['statusChanged' => false];
    }

    private function changeTimedoutStatus(Job $job, array $data, Translator $changedTranslator): bool
    {
        $job->status = $data['status'];

        $email = filled($job->user_email) ?  $job->user_email : $job->user->email;

        $dataEmail = [
            'user' => $job->user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            // this will automatically update the timestampes
            $job->update([
                'emailsent' => 0,
                'emailsenttovirpal' => 0
            ]);

            // assigning language to a variable instead of calling in string concatenation
            $job_data = $this->jobToData($job->load('user.userMeta'));
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            // string concatenation like this is more readable and easy to handle
            $subject = "Vi har nu återöppnat er bokning av {$language} tolk för bokning # {$job->id}";
            $this->mailer->send($email, $job->user->name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators
            return true;
        } elseif ($changedTranslator) {
            $subject = "Bekräftelse - tolk har accepterat er bokning (bokning # $job->id";
            $this->mailer->send($email, $job->user->name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }
        return false;
    }

    private function changeStartedStatus(Job $job, array $data): bool
    {
        // early returns save execuion time
        if (!filled($data['admin_comments'])) return false;
        if (!filled($data['sesion_time'])) return false;

        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            $interval = $data['sesion_time'];
            $job->end_at = now();
            $job->session_time = $interval;
            $diff = explode(':', $interval);
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            $dataEmail = [
                'user'         => $job->user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = "Information om avslutad tolkning för bokningsnummer # $job->id";
            $this->mailer->send($job->user_email ?? $job->user->email, $job->user, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];

            $this->mailer->send($email, $job->user->name, $subject, 'emails.session-ended', $dataEmail);
        }
        $job->save();
        return true;
    }

    private function changePendingStatus(Job $job, array $data, Translator $changedTranslator): bool
    {
        if (!filled($data['admin_comments']) && $data['status'] == 'timedout') return false;

        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];

        $dataEmail = [
            'user'         => $job->user,
            'job'          => $job,
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $subject = "Bekräftelse - tolk har accepterat er bokning (bokning # $job->id)";
            $this->mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($job->user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            $job->save();

            return true;
        }

        $subject = "Avbokning av bokningsnr: # $job->id";
        $this->mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        return true;
    }

    public function sendSessionStartRemindNotification(User $user, Job $job, Language $language, $due, $duration): void
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        $msgText = $job->customer_physical_type == 'yes'
            ? array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            )
            : array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($user->isNeedToSendPush()) {
            // using model functions
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msgText, $user->isNeedToDelayPush());
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    private function changeWithdrawafter24Status(Job $job, array $data)
    {
        if (!filled($data['admin_comments'])) return false;

        // no need to send 1 element in array
        if ($data['status'] == 'timedout') {
            // using model event makes model readable and manageable
            $job->update([
                'status' => $data['status'],
                'admin_comments' => $data['admin_comments'],
            ]);

            return true;
        }

        return false;
    }

    private function changeAssignedStatus($job, $data): bool
    {
        if (!in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            return false;
        }

        if (!filled($data['admin_comments']) && $data['status'] == 'timedout') return false;

        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
            $dataEmail = [
                'user' => $job->user,
                'job'  => $job
            ];

            $subject = "Information om avslutad tolkning för bokningsnummer # $job->id";
            $this->mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

            $user = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();

            if ($user) {
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $this->mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
        }
        $job->save();
        return true;
    }

    private function createNewTranslator(Translator $currentTranslator)
    {
        $newTranslator = $currentTranslator->toArray();
        $newTranslator['user_id'] = $data['translator'];
        unset($newTranslator['id']);
        $newTranslator = Translator::create($newTranslator);

        return $newTranslator;
    }

    private function changeTranslator(Tranlator $currentTranslator, array $data, Job $job)
    {
        if (
            !is_null($currentTranslator) || (isset($data['translator'])
                && $data['translator'] != 0) || filled($data['translator_email'])
        ) {
            $log_data = [];

            if (
                !is_null($currentTranslator) && ((isset($data['translator'])
                    && $currentTranslator->user_id != $data['translator']) || filled($data['translator_email']))
                && (isset($data['translator']) && $data['translator'] != 0)
            ) {
                if (filled($data['translator_email'])) {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }

                $newTranslator = $this->createNewTranslator($currentTranslator);
                $currentTranslator->update([
                    'cancel_at' => now()
                ]);

                $log_data[] = [
                    'old_translator' => $currentTranslator->user->email,
                    'newTranslator' => $newTranslator->user->email
                ];
            } elseif (is_null($currentTranslator) && isset($data['translator']) && ($data['translator'] != 0 || filled($data['translator_email']))) {
                if (filled($data['translator_email'])) {
                    $data['translator'] = User::whereEmail($data['translator_email'])->first('id');
                }

                $newTranslator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'newTranslator' => $newTranslator->user->email
                ];
            }
            return ['translatorChanged' => true, 'newTranslator' => $newTranslator, 'log_data' => $log_data];
        }

        return ['translatorChanged' => false];
    }

    private function changeDue($old_due, $new_due)
    {
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];

            return ['dateChanged' => true, 'log_data' => $log_data];
        }

        return ['dateChanged' => false];
    }

    public function sendChangedTranslatorNotification(Job $job, Translator $currentTranslator, Translator $newTranslator): void
    {
        $subject = "Meddelande om tilldelning av tolkuppdrag för uppdrag # $job->id";
        $data = [
            'user' => $job->user,
            'job'  => $job
        ];
        $this->mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($currentTranslator) {
            $data['user'] = $currentTranslator->user;

            $this->mailer->send($currentTranslator->user->email, $currentTranslator->user->name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $data['user'] = $newTranslator->user;
        $this->mailer->send($newTranslator->user->email, $newTranslator->user->name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    public function sendChangedDateNotification(Job $job, $oldTime)
    {
        $subject = "Meddelande om ändring av tolkbokning för uppdrag # $job->id";
        $data = [
            'user'     => $job->user,
            'job'      => $job,
            'oldTime' => $oldTime
        ];
        $this->mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'oldTime' => $oldTime
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendChangedLangNotification(Job $job, $oldLang)
    {
        $subject = "Meddelande om ändring av tolkbokning för uppdrag # $job->id";
        $data = [
            'user'     => $job->user,
            'job'      => $job,
            'oldLang' => $oldLang
        ];
        $this->mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendExpiredNotification(Job $job, User $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msgText = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($user->isNeedToSendPush()) {
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msgText, $user->isNeedToDelayPush());
        }
    }

    public function sendNotificationByAdminCancelJob(Job $job)
    {
        $data = $job->toArray();

        [$data['due_date'], $data['due_time']] = explode(" ", $job->due);
        $data['job_for'] = array();
        if ($job->gender) {
            $data['job_for'][] = $job->gender == 'male'
                ? 'Man'
                : 'female';
        }

        if ($job->certified) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";

        foreach ($users as $key => $user) {
            if ($key > 0) {
                $user_tags .= ',{"operator": "OR"},{"key": "email", "relation": "=", "value": "' . strtolower($user->email) . '"}';
            }
        }

        return $user_tags .= ']';
    }

    public function acceptJob(Job $job, User $cuser)
    {
        if (!Job::isTranslatorAlreadyBooked($job->id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job->id)) {
                $job->update(['status' => 'assigned']);
                $subject = "Bekräftelse - tolk har accepterat er bokning (bokning # $job->id ')";
                $mailer = new AppMailer();

                $data = [
                    'user' => $job->user,
                    'job'  => $job
                ];
                $mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.job-accepted', $data);
            }
            $jobs = $this->getPotentialJobs($cuser);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    public function acceptJobWithId(Job $job, User $cuser)
    {

        if (!Job::isTranslatorAlreadyBooked($job->id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job->id)) {
                $job->update(['status' => 'assigned']);
                $subject = "Bekräftelse - tolk har accepterat er bokning (bokning # $job->id ')";
                $mailer = new AppMailer();
                $data = [
                    'user' => $job->user,
                    'job'  => $job
                ];

                $mailer->send($job->user_email ?? $job->user->email, $job->user->name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($job->user->isNeedToSendPush($job->user->id)) {
                    $this->sendPushNotificationToSpecificUsers([$job->user], $job->id, $data, $msgText, $job->user->isNeedToDelayPush($job->user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax(array $data, User $cuser)
    {
        $response = array();
        $job = Job::findOrFail($data['job_id']);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
            $job->withdraw_at = now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();

            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );

                if ($translator->user->isNeedToSendPush($translator->id)) {
                    $this->sendPushNotificationToSpecificUsers([$translator], $job->id, $data, $msgText, $translator->user->isNeedToDelayPush($translator->id)); // send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(now()) > 24) {
                $customer = $job->user;

                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msgText = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($customer->isNeedToSendPush($customer->id)) {
                        $this->sendPushNotificationToSpecificUsers([$customer], $job->id, $data, $msgText, $customer->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->update([
                    'status' => 'pending',
                    'will_expire_at' => TeHelper::willExpireAt($job->due, now())
                ]);

                Job::deleteTranslatorJobRel($translator->id, $job->id);
                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    public function getPotentialJobs(User $cuser)
    {
        // assuming from where this is called the userMeta is eager loaded
        $cuserMeta = $cuser->userMeta;
        $job_type = match ($cuserMeta->translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid'
        };

        $languages = UserLanguages::whereBelongsTo($cuser)->get();
        $userlanguage = collect($languages)->pluck('lang_id');

        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $jobs = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $cuserMeta->gender, $cuserMeta->translator_level);
        foreach ($jobs as $k => $job) {
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($job->user_id, $cuser->id);

            if ($job->specific_job == 'SpecificJob') {
                if ($job->check_particular_job == 'userCanNotAcceptJob') {
                    unset($job_ids[$k]);
                }
            }
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '')
                && $job->customer_physical_type == 'yes' && $checktown == false
            ) {
                unset($job_ids[$k]);
            }
        }

        return $job;
    }

    public function endJob($postData)
    {
        $this->jobEnd($postData);
        $job = Job::findById($postData["job_id"]);
        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($postData['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->update([
            'completed_at' => now(),
            'completed_by' => $postData['user_id']
        ]);
        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($postData)
    {
        $job = Job::with('translatorJobRel')->find($postData["job_id"]);

        $job->update([
            'status' => 'not_carried_out_customer',
            'end_at' => now(),
            'completed_at' => now(),
            'completed_by' => $postData['user_id']
        ]);

        $tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();

        $tr->update([
            'complated_at' => now(),
            'complated_by' => $tr->user_id
        ]);

        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == RolesEnum::SUPER_ADMIN_ID) {
            $allJobs = Job::query();

            if (isset($request['feedback']) && $request['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($request['count']) && $request['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($request['id']) && $request['id'] != '') {
                if (is_array($request['id']))
                    $allJobs->whereIn('id', $request['id']);
                else
                    $allJobs->where('id', $request['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if (isset($request['lang']) && $request['lang'] != '') {
                $allJobs->whereIn('from_language_id', $request['lang']);
            }
            if (isset($request['status']) && $request['status'] != '') {
                $allJobs->whereIn('status', $request['status']);
            }
            if (isset($request['expired_at']) && $request['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $request['expired_at']);
            }
            if (isset($request['will_expire_at']) && $request['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $request['will_expire_at']);
            }
            if (isset($request['customer_email']) && count($request['customer_email']) && $request['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $request['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($request['translator_email']) && count($request['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $request['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }
            if (isset($request['filter_timetype']) && $request['filter_timetype'] == "created") {
                if (isset($request['from']) && $request['from'] != "") {
                    $allJobs->where('created_at', '>=', $request["from"]);
                }
                if (isset($request['to']) && $request['to'] != "") {
                    $to = $request["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($request['filter_timetype']) && $request['filter_timetype'] == "due") {
                if (isset($request['from']) && $request['from'] != "") {
                    $allJobs->where('due', '>=', $request["from"]);
                }
                if (isset($request['to']) && $request['to'] != "") {
                    $to = $request["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            if (isset($request['job_type']) && $request['job_type'] != '') {
                $allJobs->whereIn('job_type', $request['job_type']);
                /*$allJobs->where('jobs.job_type', '=', $request['job_type']);*/
            }

            if (isset($request['physical'])) {
                $allJobs->where('customer_physical_type', $request['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($request['phone'])) {
                $allJobs->where('customer_phone_type', $request['phone']);
                if (isset($request['physical']))
                    $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($request['flagged'])) {
                $allJobs->where('flagged', $request['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($request['distance']) && $request['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if (isset($request['salary']) &&  $request['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($request['count']) && $request['count'] == 'true') {
                $allJobs = $allJobs->count();

                return ['count' => $allJobs];
            }

            if (isset($request['consumer_type']) && $request['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata) {
                    $q->where('consumer_type', $request['consumer_type']);
                });
            }

            if (isset($request['booking_type'])) {
                if ($request['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');
                if ($request['booking_type'] == 'phone')
                    $allJobs->where('customer_phone_type', 'yes');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);
        } else {

            $allJobs = Job::query();

            if (isset($request['id']) && $request['id'] != '') {
                $allJobs->where('id', $request['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }
            if (isset($request['feedback']) && $request['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($request['count']) && $request['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($request['lang']) && $request['lang'] != '') {
                $allJobs->whereIn('from_language_id', $request['lang']);
            }
            if (isset($request['status']) && $request['status'] != '') {
                $allJobs->whereIn('status', $request['status']);
            }
            if (isset($request['job_type']) && $request['job_type'] != '') {
                $allJobs->whereIn('job_type', $request['job_type']);
            }
            if (isset($request['customer_email']) && $request['customer_email'] != '') {
                $user = DB::table('users')->where('email', $request['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }
            if (isset($request['filter_timetype']) && $request['filter_timetype'] == "created") {
                if (isset($request['from']) && $request['from'] != "") {
                    $allJobs->where('created_at', '>=', $request["from"]);
                }
                if (isset($request['to']) && $request['to'] != "") {
                    $to = $request["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($request['filter_timetype']) && $request['filter_timetype'] == "due") {
                if (isset($request['from']) && $request['from'] != "") {
                    $allJobs->where('due', '>=', $request["from"]);
                }
                if (isset($request['to']) && $request['to'] != "") {
                    $to = $request["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);
        }
        return $allJobs;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs[$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        // using local scopes on model
        $languages = Language::active()->orderBy('language')->get();

        // lists is deprecated and using eloquent to trigger model events
        $all_customers = User::userType(UserTypesEnum::ONE)->pluck('email');
        $all_translators = ser::userType(UserTypesEnum::TWO)->pluck('email');

        $cuser = Auth::user();


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($request['lang']) && $request['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $request['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $request['lang']);*/
            }
            if (isset($request['status']) && $request['status'] != '') {
                $allJobs->whereIn('jobs.status', $request['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $request['status']);*/
            }
            if (isset($request['customer_email']) && $request['customer_email'] != '') {
                $user = DB::table('users')->where('email', $request['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($request['translator_email']) && $request['translator_email'] != '') {
                $user = DB::table('users')->where('email', $request['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($request['filter_timetype']) && $request['filter_timetype'] == "created") {
                if (isset($request['from']) && $request['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $request["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($request['to']) && $request['to'] != "") {
                    $to = $request["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($request['filter_timetype']) && $request['filter_timetype'] == "due") {
                if (isset($request['from']) && $request['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $request["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($request['to']) && $request['to'] != "") {
                    $to = $request["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($request['job_type']) && $request['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $request['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $request['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted(Request $request)
    {
        // using local scopes on model
        $languages = Language::active()->orderBy('language')->get();

        // lists is deprecated and using eloquent to trigger model events
        $all_customers = User::userType(UserTypesEnum::ONE)->pluck('email');
        $all_translators = ser::userType(UserTypesEnum::TWO)->pluck('email');

        $cuser = Auth::user();

        if ($cuser && ($cuser->is(RolesEnum::SUPER_ADMIN) || $cuser->is(RolesEnum::ADMIN))) {
            $allJobs = Job::join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($request['lang']) && filled($request['lang'])) {
                $allJobs->whereIn('jobs.from_language_id', $request['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', now());
            }
            if (isset($request['status']) && filled($request['status'])) {
                $allJobs->whereIn('jobs.status', $request['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', now());
            }
            if (isset($request['customer_email']) && filled($request['customer_email'])) {
                $user = User::whereEmail($request['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', now());
                }
            }
            if (isset($request['translator_email']) && filled($request['translator_email'])) {
                $user = User::whereEmail($request['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', now());
                }
            }
            if (isset($request['filter_timetype']) && $request['filter_timetype'] == "created") {
                if (isset($request['from']) && filled($request['from'])) {
                    $allJobs->where('jobs.created_at', '>=', $request["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', now());
                }
                if (isset($request['to']) && filled($request['to'])) {
                    $to = $request["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($request['filter_timetype']) && $request['filter_timetype'] == "due") {
                if (isset($request['from']) && $request['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $request["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', now());
                }
                if (isset($request['to']) && filled($request['to'])) {
                    $to = $request["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($request['job_type']) && filled($request['job_type'])) {
                $allJobs->whereIn('jobs.job_type', $request['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', now());
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring(Job $job): array
    {
        $job->update(['ignore' => 1]);

        return ['success', 'Changes saved'];
    }

    public function ignoreExpired(Job $job): array
    {
        $job->update(['ignore_expired' => 1]);

        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle(Throttle $throttle): arra
    {
        $throttle->update(['ignore' => 1]);

        return ['success', 'Changes saved'];
    }

    public function reopen(Job $job, $request)
    {
        $userid = $request['userid'];

        $data = array();
        $data['created_at'] = now();
        $data['will_expire_at'] = TeHelper::willExpireAt($job->due, $data['created_at']);
        $data['updated_at'] = now();
        $data['user_id'] = $userid;
        $data['job_id'] = $job->id;
        $data['cancel_at'] = now();

        $datareopen = array();
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

        if ($job['status'] != 'timedout') {
            $status = $job->update($datareopen);
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = now();
            $job['updated_at'] = now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], now());
            $job['updated_at'] = now();
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = "This booking is a reopening of booking # $job->id";
        }
        // updating using relationsip
        $job->translatorRel()->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);

        if (isset($status)) {
            $this->sendNotificationByAdminCancelJob($job->id);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }
}
