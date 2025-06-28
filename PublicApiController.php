<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Helpers\{ApiHelper, PriceHelper, SystemHelper, InsertHelper, InstantApiSupportHelper};
use App\Models\{Customer, CustomerOrder, ServiceGroup, ServiceList, ServiceInput, OrderInput, Statement, CustomPrice, SystemSetting, MailData};
use Carbon\Carbon;

class PublicApiController extends Controller
{
    public function publicApi(Request $request)
    {
        $apiVersion = '2023.21';
        $apiresults = [];

        if (SystemSetting::where('index', 13)->value('value') == 1) {
            return $this->error('Currently server is under maintenance. Please try again later.', $apiVersion);
        }
        if (SystemHelper::customerdemo()) {
            return $this->error('Demo Mode ON!', $apiVersion);
        }
        $validator = Validator::make($request->all(), [
            'username' => 'required|email|max:60',
            'apiaccesskey' => 'required|string|max:39',
            'action' => 'required|string|max:30',
        ], [
            'username.email' => 'User name must be an email',
            'username.required' => 'User name is required',
            'apiaccesskey.required' => 'API access key is required',
            'action.required' => 'Action is required',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors(), $apiVersion);
        }
        $customer = Customer::where('email', $request->username)
            ->where('api_key', $request->apiaccesskey)
            ->first();

        if (!$customer) {
            return $this->error('Authentication Failed', $apiVersion);
        }
        if ($customer->status == 'Block') {
            return $this->error('You are Blocked!', $apiVersion);
        }
        if ($customer->api_allow != 'on') {
            return $this->error('API is inactive!', $apiVersion);
        }
        return match ($request->action) {
            'accountinfo' => $this->accountInfo($customer, $apiVersion),
            'imeiservicelist' => $this->imeiServiceList($customer, $apiVersion),
            'getimeiorder' => $this->getImeiOrder($request, $customer, $apiVersion),
            'getimeiorderbulk' => $this->getImeiOrderBulk($request, $customer, $apiVersion),
            'placeimeiorder' => $this->placeImeiOrder($request, $customer, $apiVersion),
            default => $this->error('Invalid Action', $apiVersion),
        };
    }

    // RETURN AS ERROR
    private function error($message, $version, $code = 200)
    {
        $data = [
            'ERROR' => [['MESSAGE' => $message]],
            'apiversion' => $version
        ];
        return response()
            ->json($data, $code)
            ->header('X-Powered-By', 'GSM-THEME')
            ->header('gsmtheme-fusion-api-version', $version)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
    // RETURN AS SUCCESS
    private function success(array $data, $version, $code = 200)
    {
        $data['apiversion'] = $version;
        return response()
            ->json($data, $code)
            ->header('X-Powered-By', 'GSM-THEME')
            ->header('gsmtheme-fusion-api-version', $version)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
    // BASE64 VALIDATION
    private function isBase64($str)
    {
        return preg_match('/^[A-Za-z0-9+\/=]+$/', $str) && base64_encode(base64_decode($str, true)) === $str;
    }

    // ACCOUNT INFO
    private function accountInfo($customer, $version)
    {
        try {
            return $this->success([
                'SUCCESS' => [[
                    'MESSAGE' => 'Your Account Info',
                    'AccountInfo' => [
                        'credit' => round($customer->balance, 2) . ' ' . $customer->currency,
                        'creditraw' => round($customer->balance, 2),
                        'mail' => $customer->email,
                        'currency' => $customer->currency
                    ]
                ]]
            ], $version);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $version, 500);
        }
    }

    // SERVICE LIST
    private function imeiServiceList($customer, $version)
    {
        try {

            if ($customer->next_account_sync > now()) {
                $nextTime = now()->diffInMinutes($customer->next_account_sync);
                return $this->error('You are calling this API too frequently! Please try after ' . $nextTime . ' minutes.', $version, 200);
            }
            $customer->update([
                'next_account_sync' => now()->addMinutes(5),
            ]);

            $serviceGroups = ServiceGroup::where('status', 'Active')->orderBy('name')->get(['id', 'type', 'name', 'status']);
            $serviceList = [];
            foreach ($serviceGroups as $serviceGroup) {
                $availableService = ServiceList::where('status', 'Active')->where('service_group', $serviceGroup->id)->count();
                if($availableService){
                    $groupName = $serviceGroup->name;
                    $serviceList[$groupName] = [
                        'GROUPNAME' => $groupName,
                        'GROUPTYPE' => match (strtoupper($serviceGroup->type)) {
                            'SERVER' => 'SERVER',
                            'IMEI' => 'IMEI',
                            'REMOTE' => 'REMOTE',
                            default => 'REMOTE',
                        },
                        'SERVICES' => []
                    ];
                    $services = ServiceList::where('service_group', $serviceGroup->id)->where('status', 'Active')->orderBy('title', 'DESC')->get();
                    foreach ($services as $service) {
                        $CREDIT = ApiHelper::calculatePrice($service->id, $customer->id, 1);
                        $serviceList[$groupName]['SERVICES'][$service->id] = [
                            'SERVICEID' => $service->id,
                            'SERVICETYPE' => match (strtoupper($serviceGroup->type)) {
                                'SERVER' => 'SERVER',
                                'IMEI' => 'IMEI',
                                'REMOTE' => 'REMOTE',
                                default => 'REMOTE',
                            },
                            'SERVER' => match (strtoupper($serviceGroup->type)) {
                                'SERVER' => 1,
                                'IMEI' => 0,
                                'REMOTE' => 2,
                                default => 2,
                            },
                            'QNT' => $service->min_qnt ? 1 : 0,
                            'MINQNT' => $service->min_qnt,
                            'MAXQNT' => $service->max_qnt,
                            'SERVICENAME' => $service->title,
                            'CREDIT' => $CREDIT,
                            'TIME' => $service->delivery_time,
                            'INFO' => ''
                        ];
                        $inputFields = ServiceInput::where('service_id', $service->id)->get();
                        $customFields = $service->service_type === 'IMEI' ? $inputFields->skip(1) : $inputFields;
                        if ($service->service_type === 'IMEI' && $inputFields->first()) {
                            $serviceList[$groupName]['SERVICES'][$service->id]['CUSTOM'] = [
                                'allow' => '1',
                                'bulk' => '0',
                                'customname' => $inputFields->first()->name,
                                'custominfo' => '',
                                'customlen' => '1',
                                'maxlength' => '300',
                                'regex' => '',
                                'isalpha' => '1'
                            ];
                        }
                        if ($customFields->isNotEmpty()) {
                            $custom = [];
                            foreach ($customFields as $i => $input) {
                                $custom[$i] = [
                                    'type' => 'serviceimei',
                                    'fieldname' => $input->name,
                                    'fieldtype' => 'text',
                                    'description' => '',
                                    'fieldoptions' => '',
                                    'required' => 'on'
                                ];
                            }
                            $serviceList[$groupName]['SERVICES'][$service->id]['Requires.Custom'] = $custom;
                        }
                    }
                }
            }
            return $this->success([
                'SUCCESS' => [[
                    'MESSAGE' => 'Service List',
                    'LIST' => $serviceList,
                    'ACCOUNTINFO' => [
                        'credit' => round($customer->balance, 2) . ' ' . $customer->currency,
                        'creditraw' => round($customer->balance, 2),
                        'mail' => $customer->email,
                        'currency' => $customer->currency
                    ]
                ]]
            ], $version);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $version, 500);
        }
    }
    // ORDER HISTORY
    private function getImeiOrder(Request $request, $customer, $version)
    {
        try {
            $params = simplexml_load_string($request->parameters);
            if (!$params || !isset($params->ID)) {
                return $this->error('Parameter required.', $version, 400);
            }

            $order = CustomerOrder::where('customer_id', $customer->id)->find((int)$params->ID);
            if (!$order) {
                return $this->error('Order ID not found!', $version, 404);
            }

            $statusMap = [
                'Success' => 4,
                'Rejected' => 3,
                'In Process' => 1,
                'Waiting Action' => 0
            ];

            $status = $statusMap[$order->service_status] ?? -1;

            return $this->success([
                'SUCCESS' => [[
                    'STATUS' => $status,
                    'CODE' => $order->service_comments
                ]]
            ], $version);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $version, 500);
        }
    }
    // ORDER HISTORY BULK
    private function getImeiOrderBulk(Request $request, $customer, $version)
    {
        try {
            $params = simplexml_load_string($request->parameters);
            if (!$params || !isset($params->ID)) {
                return $this->error('Parameter required.', $version, 400);
            }

            $ids = array_map('intval', explode(',', (string)$params->ID));
            $orders = CustomerOrder::where('customer_id', $customer->id)->whereIn('id', $ids)->get()->keyBy('id');

            $result = [];
            foreach ($ids as $id) {
                if (!isset($orders[$id])) {
                    $result['ERROR'][] = ['MESSAGE' => "Order ID {$id} not found!"];
                    continue;
                }

                $order = $orders[$id];
                $statusMap = [
                    'Success' => 4,
                    'Rejected' => 3,
                    'In Process' => 1,
                    'Waiting Action' => 0
                ];

                $result['SUCCESS'][$id] = [
                    'STATUS' => $statusMap[$order->service_status] ?? -1,
                    'CODE' => $order->service_comments,
                    'COMMENTS' => $order->service_comments ?? ''
                ];
            }

            $result['ID'] = implode(',', $ids);

            return $this->success($result, $version);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $version, 500);
        }
    }
    // PLACE ORDER
    private function placeImeiOrder(Request $request, $customer, $version)
    {
        try {
            $params = @simplexml_load_string($request->parameters);
            if (!$params || !isset($params->ID)) {
                return $this->error('Parameter or Service <ID> missing.', $version, 400);
            }
            // Check service status
            $service = ServiceList::find((int)$params->ID);
            if (!$service || $service->status === 'Inactive') {
                return $this->error('Service not found or inactive.', $version, 404);
            }
            $serviceFields = ServiceInput::where('service_id', $service->id)->get();
            // Ckeck IMEI params
            if ($service->service_type === 'IMEI' && empty((string)$params->IMEI)) {
                if($serviceFields){
                    return $this->error('IMEI field is required.', $version, 400);
                }
            }
            // Custom field (base64 JSON)
            $incommingFields = [];
            if (!empty($params->CUSTOMFIELD)) {
                $customField = (string)$params->CUSTOMFIELD;
                if (!$this->isBase64($customField)) {
                    return $this->error('CUSTOMFIELD must be base64 encoded.', $version, 400);
                }
                $incommingFields = json_decode(base64_decode($customField), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->error('CUSTOMFIELD must decode to valid JSON.', $version, 400);
                }
            }
            // Validate additional required fields
            if ($service->service_type === 'IMEI') {
                $serviceFields = $serviceFields->skip(1);
            }
            $requiredFields = $serviceFields->pluck('name')->toArray();
            if($requiredFields){
                foreach ($requiredFields as $field) {
                if (empty($incommingFields[$field])) {
                    return $this->error("$field is required.", $version, 400);
                }
            }
            }
            // Quantity
            $quantity = (int)$params->QNT ?: 1;
            $price = ApiHelper::calculatePrice($service->id, $customer->id, $quantity);

            // Balance Process
            if(!$price && !$service->free_service){
                return $this->error('Balance process error!', $version, 400);
            }

            if($service->free_service){
                $price = 0;
            }

            $balanceProcess = PriceHelper::balanceProcess($price, $customer, $service);
            if(!$balanceProcess){
                return $this->error('Not enough balance!', $version, 400);
            }
            // Decrement balance
            Customer::find($customer->id)->decrement('balance', $price);

            // First input
            $firstInput = $service->service_type === 'IMEI'
                ? (string)$params->IMEI
                : (is_array($incommingFields) ? ($incommingFields[array_key_first($incommingFields)] ?? null) : null);

            // Create order
            $order = CustomerOrder::create([
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'invoice_status' => 'paid',
                'currency' => $customer->currency,
                'service_type' => $service->service_type,
                'service_id' => $service->id,
                'service_title' => $service->title,
                'service_qnt' => $quantity,
                'service_price' => $price,
                'payment_methode' => 'My Funds',
                'trx_id' => '-',
                'service_status' => $service->process_type === 'Inventory' ? 'Success' : 'Waiting Action',
                'process_type' => $service->process_type,
                'api_id' => $service->api_id,
                'remote_service_id' => $service->referenceid,
                'service_input1' => $firstInput,
                'created_at' => now(),
            ]);
            
            // Save custom inputs
            $serviceFields = [];
            if ($service->service_type === 'IMEI') {
                $imeiField = ServiceInput::where('service_id', $service->id)->first()?->name;
                if($imeiField){
                    $serviceFields[] = [
                        'field_name' => $imeiField,
                        'field_value' => (string)$params->IMEI,
                        'order_id' => $order->id
                    ];
                }
            }
            foreach ($requiredFields as $name) {
                if (!empty($incommingFields[$name])) {
                    $serviceFields[] = [
                        'field_name' => $name,
                        'field_value' => $incommingFields[$name],
                        'order_id' => $order->id
                    ];
                }
            }
            if ($serviceFields) {
                OrderInput::insert($serviceFields);
            }

            // Increase sells count
            $service->increment('sells');
            // Add statement
            InsertHelper::insertStatement($customer, 'Place Order (Api)', 'Debit', $order->service_price, $order->id, $order->service_title, Customer::find($customer->id)->balance);
            // Save mail data
            InsertHelper::customerOrderEmailNotification($customer, $order, 'Api');
            InsertHelper::adminOrderEmailNotification($customer, $order, 'Api');
            // Inventory Process
            if ($service->process_type === 'Inventory') {
                PriceHelper::inventoryProcess($service->referenceid, $order->id);
            }
            // Instant API Process
            if($service->process_type == 'Api'){
                InstantApiSupportHelper::apiProcess($service->id, $order->id);
            }

            return $this->success([
                'SUCCESS' => [[
                    'MESSAGE' => 'Order received',
                    'REFERENCEID' => $order->id
                ]]
            ], $version);

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $version, 500);
        }
    }

}
