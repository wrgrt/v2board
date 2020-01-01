<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderSave;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\Coupon;
use App\Utils\Helper;
use Omnipay\Omnipay;
use Stripe\Stripe;
use Stripe\Source;
use Library\BitpayX;

class OrderController extends Controller
{
    public function fetch (Request $request) {
        $order = Order::where('user_id', $request->session()->get('id'))
            ->orderBy('created_at', 'DESC')
            ->get();
        $plan = Plan::get();
        for($i = 0; $i < count($order); $i++) {
            for($x = 0; $x < count($plan); $x++) {
                if ($order[$i]['plan_id'] === $plan[$x]['id']) {
                    $order[$i]['plan'] = $plan[$x];
                }
            }
        }
        return response([
            'data' => $order
        ]);
    }
    
    public function details (Request $request) {
        $order = Order::where('user_id', $request->session()->get('id'))
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, '订单不存在');
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['update_fee'] = config('v2board.plan_update_fee', 0.5);
        if (!$order['plan']) {
            abort(500, '订阅不存在');
        }
        return response([
            'data' => $order
        ]);
    }
    
    public function save (OrderSave $request) {
        $plan = Plan::find($request->input('plan_id'));
        $user = User::find($request->session()->get('id'));
        
        if (!$plan) {
            abort(500, '该订阅不存在');
        }
        
        if (!($plan->show || $user->plan_id == $plan->id)) {
            abort(500, '该订阅已售罄');
        }

        if (!$plan->show && !$plan->renew) {
            abort(500, '该订阅无法续费，请更换其他订阅');
        }

        if ($plan[$request->input('cycle')] === NULL) {
            abort(500, '该订阅周期无法进行购买，请选择其他周期');
        }

        if ($request->input('coupon_code')) {
            $coupon = Coupon::where('code', $request->input('coupon_code'))->first();
            if (!$coupon) {
                abort(500, '优惠券无效');
            }
            if (time() < $coupon->started_at) {
                abort(500, '优惠券还未到可用时间');
            }
            if (time() > $coupon->ended_at) {
                abort(500, '优惠券已过期');
            }
        }
        
        $order = new Order();
        $order->user_id = $request->session()->get('id');
        $order->plan_id = $plan->id;
        $order->cycle = $request->input('cycle');
        $order->trade_no = Helper::guid();
        $order->total_amount = $plan[$request->input('cycle')];
        // renew and change subscribe process
        if ($user->expired_at > time() && $order->plan_id !== $user->plan_id) {
            $order->type = 3;
            if (!(int)config('v2board.plan_is_update', 1)) abort(500, '目前不允许更改订阅，请联系管理员');
            $order->total_amount = $order->total_amount + (ceil(($user->expired_at - time()) / 86400) * config('v2board.plan_update_fee', 0.5) * 100);
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
            $order->type = 2;
        } else {
            $order->type = 1;
        }
        // invite process
        if ($user->invite_user_id) {
            $order->invite_user_id = $user->invite_user_id;
            $inviter = User::find($user->invite_user_id);
            if ($inviter && $inviter->commission_rate) {
                $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
            } else {
                $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
            }
        }
        // coupon process
        if (isset($coupon)) {
            switch ($coupon->type) {
                case 1: $order->discount_amount = $order->total_amount - $coupon->value;
                    break;
                case 2: $order->discount_amount = $order->total_amount * ($coupon->value / 100);
                    break;
            }
            $order->total_amount = $order->total_amount - $order->discount_amount;
        }
        // free process
        if ($order->total_amount <= 0) {
            $order->status = 1;
        }
        if (!$order->save()) {
            abort(500, '订单创建失败');
        }
        return response([
            'data' => $order->trade_no
        ]);
    }

    public function checkout (Request $request) {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->session()->get('id'))
            ->where('status', 0)
            ->first();
        if (!$order) {
            abort(500, '订单不存在或已支付');
        }
        switch ($method) {
            // return type => 0: QRCode / 1: URL
            case 0:
                // alipayF2F
                if (!(int)config('v2board.alipay_enable')) {
                    abort(500, '支付方式不可用');
                }
                return response([
                    'type' => 0,
                    'data' => $this->alipayF2F($tradeNo, $order->total_amount)
                ]);
            case 2:
                // stripeAlipay
                if (!(int)config('v2board.stripe_alipay_enable')) {
                    abort(500, '支付方式不可用');
                }
                return response([
                    'type' => 1,
                    'data' => $this->stripeAlipay($order)
                ]);
            case 3:
                // stripeWepay
                if (!(int)config('v2board.stripe_wepay_enable')) {
                    abort(500, '支付方式不可用');
                }
                return response([
                    'type' => 0,
                    'data' => $this->stripeWepay($order)
                ]);
            case 4:
                // bitpayX
                if (!(int)config('v2board.bitpayx_enable')) {
                    abort(500, '支付方式不可用');
                }
                return response([
                    'type' => 1,
                    'data' => $this->bitpayX($order)
                ]);
            default:
                abort(500, '支付方式不存在');
        }
    }

    public function check (Request $request) {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->session()->get('id'))
            ->first();
        if (!$order) {
            abort(500, '订单不存在');
        }
        return response([
            'data' => $order->status
        ]);
    }

    public function getPaymentMethod () {
        $data = [];
        if ((int)config('v2board.alipay_enable')) {
            $alipayF2F = new \StdClass();
            $alipayF2F->name = '支付宝';
            $alipayF2F->method = 0;
            $alipayF2F->icon = 'alipay';
            array_push($data, $alipayF2F);
        }

        if ((int)config('v2board.stripe_alipay_enable')) {
            $stripeAlipay = new \StdClass();
            $stripeAlipay->name = '支付宝';
            $stripeAlipay->method = 2;
            $stripeAlipay->icon = 'alipay';
            array_push($data, $stripeAlipay);
        }

        if ((int)config('v2board.stripe_wepay_enable')) {
            $stripeWepay = new \StdClass();
            $stripeWepay->name = '微信';
            $stripeWepay->method = 3;
            $stripeWepay->icon = 'wechat';
            array_push($data, $stripeWepay);
        }

        if ((int)config('v2board.bitpayx_enable')) {
            $bitpayX = new \StdClass();
            $bitpayX->name = '虚拟货币';
            $bitpayX->method = 4;
            $bitpayX->icon = 'bitcoin';
            array_push($data, $bitpayX);
        }

        return response([
            'data' => $data
        ]);
    }

    private function alipayF2F ($tradeNo, $totalAmount) {
        $gateway = Omnipay::create('Alipay_AopF2F');
        $gateway->setSignType('RSA2'); //RSA/RSA2
        $gateway->setAppId(config('v2board.alipay_appid'));
        $gateway->setPrivateKey(config('v2board.alipay_privkey')); // 可以是路径，也可以是密钥内容
        $gateway->setAlipayPublicKey(config('v2board.alipay_pubkey')); // 可以是路径，也可以是密钥内容
        $gateway->setNotifyUrl(url('/api/v1/guest/order/alipayNotify'));
        $request = $gateway->purchase();
        $request->setBizContent([
            'subject'      => config('v2board.app_name', 'V2Board') . ' - 订阅',
            'out_trade_no' => $tradeNo,
            'total_amount' => $totalAmount / 100
        ]);
        /** @var \Omnipay\Alipay\Responses\AopTradePreCreateResponse $response */
        $response = $request->send();
        $result = $response->getAlipayResponse();
        if ($result['code'] !== '10000') {
        	abort(500, $result['sub_msg']);
        }
        // 获取收款二维码内容
        return $response->getQrCode();
    }

    private function stripeAlipay ($order) {
        $exchange = Helper::exchange('CNY', 'HKD');
        if (!$exchange) {
            abort(500, '货币转换超时，请稍后再试');
        }
        Stripe::setApiKey(config('v2board.stripe_sk_live'));
        $source = Source::create([
            'amount' => floor($order->total_amount * $exchange),
            'currency' => 'hkd',
            'type' => 'alipay',
            'redirect' => [
                'return_url' => config('v2board.app_url', env('APP_URL')) . '/#/order'
            ]
        ]);
        if (!$source['redirect']['url']) {
            abort(500, '支付网关请求失败');
        }
        
        if (!Redis::set($source['id'], $order->trade_no)) {
            abort(500, '订单创建失败');
        }
        Redis::expire($source['id'], 3600);
        return $source['redirect']['url'];
    }

    private function stripeWepay ($order) {
        $exchange = Helper::exchange('CNY', 'HKD');
        if (!$exchange) {
            abort(500, '货币转换超时，请稍后再试');
        }
        Stripe::setApiKey(config('v2board.stripe_sk_live'));
        $source = Source::create([
            'amount' => floor($order->total_amount * $exchange),
            'currency' => 'hkd',
            'type' => 'wechat',
            'redirect' => [
                'return_url' => config('v2board.app_url', env('APP_URL')) . '/#/order'
            ]
        ]);
        if (!$source['wechat']['qr_code_url']) {
            abort(500, '支付网关请求失败');
        }
        if (!Redis::set($source['id'], $order->trade_no)) {
            abort(500, '订单创建失败');
        }
        Redis::expire($source['id'], 3600);
        return $source['wechat']['qr_code_url'];
    }

    private function bitpayX ($order) {
        $bitpayX = new BitpayX(config('v2board.bitpayx_appsecret'));
    	$params = [
    		'merchant_order_id' => 'V2Board_' . $order->trade_no,
	        'price_amount' => $order->total_amount / 100,
	        'price_currency' => 'CNY',
	        'title' => '支付单号：' . $order->trade_no,
	        'description' => '充值：' . $order->total_amount / 100 . ' 元',
	        'callback_url' => url('/api/v1/guest/order/bitpayXNotify'),
	        'success_url' => config('v2board.app_url', env('APP_URL')) . '/#/order',
	        'cancel_url' => config('v2board.app_url', env('APP_URL')) . '/#/order'
        ];
        $strToSign = $bitpayX->prepareSignId($params['merchant_order_id']);
	    $params['token'] = $bitpayX->sign($strToSign);
        $result = $bitpayX->mprequest($params);
        Log::info('bitpayXSubmit: ' . json_encode($result));
        return isset($result['payment_url']) ? $result['payment_url'] : false;
    }
}
