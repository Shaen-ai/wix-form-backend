<?php

namespace App\Http\Controllers;

use App\Models\WixWebhook;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

class WixController extends Controller
{
    public function handleWixWebhooksSmartForm(Request $request)
    {
        $body      = file_get_contents('php://input');
        $publicKey = config('config.wixSmartFormPublicKey');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $decoded   = JWT::decode($body, new Key($publicKey, 'RS256'));
                $event     = json_decode($decoded->data);
                $eventData = json_decode($event->data);
                $identity  = json_decode($event->identity);
            } catch (Exception $e) {
                http_response_code(400);
                return ['type' => 'error', 'message' => $e->getMessage()];
            }

            $webhook_data = [
                'type'     => 'SmartForm ' . $event->eventType,
                'instance' => $event->instanceId,
                'content'  => ['identity' => $identity, 'data' => ''],
            ];

            if (isset($identity->wixUserId)) {
                $webhook_data['user_id'] = $identity->wixUserId;
            }

            switch ($event->eventType) {
                case 'AppInstalled':
                case 'SitePropertiesUpdated':
                    $webhook_data['origin_instance'] = isset($eventData->originInstanceId)
                        ? $eventData->originInstanceId
                        : '';
                    $webhook_data['content']['data'] = $eventData;
                    break;

                case 'AppRemoved':
                    $webhook_data['content']['data'] = $eventData;
                    break;

                case 'PaidPlanPurchased':
                case 'PaidPlanChanged':
                case 'PaidPlanAutoRenewalCancelled':
                    $webhook_data['content']['data'] = $event->data;
                    break;

                default:
                    $webhook_data['content']['data'] = $event->data;
                    break;
            }

            WixWebhook::create($webhook_data);
            http_response_code(200);
        }
    }
}
