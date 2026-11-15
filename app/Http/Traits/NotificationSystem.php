<?php

namespace App\Http\Traits;

use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

trait NotificationSystem
{
    public function sendNotification($data, $type, $event, $directNotifyUsers = [])
    {
        $notificationsSettings = app('sharedData')->notifications_settings ?? null;
        
        if (!$notificationsSettings || (is_object($notificationsSettings) && count(get_object_vars($notificationsSettings)) == 0)) {
            $dbSetting = Settings::where('type', 'notifications_settings')->whereNotNull('data')->first();
            $notificationsSettings = $dbSetting?->data;
        }

        if (is_string($notificationsSettings)) {
            $notificationsSettings = json_decode($notificationsSettings);
        }

        if (is_array($notificationsSettings)) {
            $notificationsSettings = json_decode(json_encode($notificationsSettings));
        }

        if (!$notificationsSettings) {
            return;
        }

        $typeKey = ucfirst($type);
        $settings = $notificationsSettings->{$typeKey} ?? $notificationsSettings->{$type} ?? null;
        if (!$settings) {
            return;
        }

        $eventLower = strtolower($event);
        $actionEnabled = $settings->actions->{$eventLower} ?? $settings->actions->{$event} ?? null;
        if ($actionEnabled != 1) {
            return;
        }

        # Get Channels
        $channels = [];
        if (isset($settings->channels->database) && $settings->channels->database == 1) {
            $channels[] = 'database';
        }
        if (isset($settings->channels->email) && $settings->channels->email == 1) {
            $channels[] = 'mail';
        }
        if (isset($settings->channels->sms) && $settings->channels->sms == 1) {
            $channels[] = 'sms';
        }

        if (count($channels) == 0) {
            return;
        }

        $userColumns = [
            'id',
            'name',
            'email',
            'full_mobile_number',
            'mobile_without_country_code',
            'mobile_country_code',
        ];

        $users = collect([]);

        if (!empty($directNotifyUsers) && count($directNotifyUsers)) {
            $users = collect($directNotifyUsers);
        } else {
            # Get Determination Users
            if (!empty($settings->determination_users_data)) {
                $determinationUsersIds = collect($settings->determination_users_data)->pluck('id');
                $determinationUsers = User::whereIn('id', $determinationUsersIds)->select($userColumns)->get();
                $users = $users->merge($determinationUsers);
            }

            # Get Users By Roles
            if (!empty($settings->users_roles_data)) {
                $usersByRolesIds = collect($settings->users_roles_data)->pluck('id');
                $usersByRoles = User::whereHas('roles', function ($query) use ($usersByRolesIds) {
                    $query->whereIn('id', $usersByRolesIds);
                })->select($userColumns)->get();
                $users = $users->merge($usersByRoles);
            }

            # Get Related Contacts Users
            if (!empty($settings->notify_related_contacts) && !empty($data->related_contacts) && is_array($data->related_contacts)) {
                $contacts = $data->related_contacts;
                $usersContacts = User::whereIn('id', $contacts)->select($userColumns)->get();
                $users = $users->merge($usersContacts);
            }

            # Also notify assigned responsible user if present
            if (!empty($data->responsible_id)) {
                $assignee = User::where('id', $data->responsible_id)->select($userColumns)->get();
                $users = $users->merge($assignee);
            } elseif (!empty($data->user_id)) {
                $creator = User::where('id', $data->user_id)->select($userColumns)->get();
                $users = $users->merge($creator);
            }

            $users = $users->filter(function($u) use ($channels) {
                if (in_array('database', $channels)) {
                    return !empty($u->id);
                }
                $hasEmail = in_array('mail', $channels) && !empty($u->email);
                $hasSms = in_array('sms', $channels) && (!empty($u->full_mobile_number) || !empty($u->phone));
                return $hasEmail || $hasSms || !empty($u->email) || !empty($u->full_mobile_number);
            })->unique('id');
        }

        if ($users->isEmpty()) {
            return;
        }

        # Event Name
        $event = ucwords($event);

        # Call Notification Class
        $notifiactionClass = 'App\Notifications\\' . ucfirst($type) . 'Notification';
        if (class_exists($notifiactionClass)) {
            $notificationInstance = new $notifiactionClass($channels, $data, $event, $type);
            try {
                Notification::send($users, $notificationInstance);
            } catch (TransportExceptionInterface $exception) {
                Log::error('Notification email delivery failed.', [
                    'notification_type' => $type,
                    'event' => $event,
                    'exception' => get_class($exception),
                ]);
            }
        }
    }
}
