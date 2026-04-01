<?php

class vmqrcode_plugin
{
    static public $info = [
        'name'        => 'vmqrcode',
        'showname'    => 'V免签二维码收款',
        'author'      => 'Epay',
        'link'        => '#',
        'types'       => ['alipay', 'wxpay'],
        'inputs' => [
            'appkey' => [
                'name' => '通讯密钥',
                'type' => 'input',
                'note' => '用于签名验证的密钥',
            ],
            'appurl' => [
                'name' => '收款二维码地址',
                'type' => 'input',
                'note' => '收款二维码图片URL地址，多个地址用英文逗号分隔，每次随机选择一个',
            ],
        ],
        'select'   => null,
        'note'     => '个人二维码收款插件。V免签后台回调地址请填写：<u>{站点地址}/pay/notify/{通道ID}/</u>',
        'bindwxmp' => false,
        'bindwxa'  => false,
    ];

    /**
     * 获取去重后的实际支付金额
     * 检查同通道下是否存在相同金额的未支付订单，如有则递增0.01
     */
    static private function getUniqueAmount()
    {
        global $DB, $order, $channel;

        $amount = floatval($order['realmoney']);
        $channelId = $channel['id'];

        // 查询同通道下5分钟内未支付的订单金额列表
        $list = $DB->getAll(
            "SELECT realmoney FROM pre_order WHERE channel=? AND status=0 AND trade_no!=? AND addtime>=DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
            [$channelId, TRADE_NO]
        );

        if (empty($list)) {
            return number_format($amount, 2, '.', '');
        }

        // 提取已存在的金额
        $existAmounts = array_column($list, 'realmoney');

        // 如果存在相同金额，递增0.01直到找到唯一金额
        while (in_array(number_format($amount, 2, '.', ''), $existAmounts)) {
            $amount = round($amount + 0.01, 2);
        }

        $realAmount = number_format($amount, 2, '.', '');

        // 更新订单实际支付金额
        $DB->exec("UPDATE pre_order SET realmoney=? WHERE trade_no=?", [$realAmount, TRADE_NO]);

        return $realAmount;
    }

    static public function submit()
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
    }

    static public function mapi()
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
    }

    // 二维码支付页面
    static public function qrcode()
    {
        global $siteurl, $cdnpublic, $order, $channel, $conf;

        $amount = self::getUniqueAmount();
        $urls = array_filter(array_map('trim', explode(',', $channel['appurl'])));
        $qrcode_url = $urls[array_rand($urls)];

        include PAY_ROOT . 'page/qrcode.page.php';
        exit;
    }

    // 异步回调通知
    static public function notify()
    {
        global $channel, $order, $DB;

        ob_clean();
        header('Content-Type: text/plain; charset=utf-8');

        // 回调参数(POST JSON)：商户ID(payId)、支付方式(type)、支付金额(price)、签名(sign)
        $data = json_decode(file_get_contents('php://input'), true);
        $payId = $data['payId'] ?? '';
        $type  = $data['type'] ?? '';
        $price = $data['price'] ?? '';
        $sign  = $data['sign'] ?? '';

        if (empty($payId) || empty($type) || empty($price) || empty($sign)) {
            exit('fail - missing params');
        }

        // 验证签名: MD5(payId + type + price + 通讯密钥)
        $mysign = md5($payId . $type . $price . $channel['appkey']);
        if ($sign !== $mysign) {
            exit('fail - sign error');
        }

        // 通过 payId(商户ID) + type(支付方式) + price(金额) 三重匹配订单
        $matchOrder = $DB->getRow(
            "SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel=? AND A.uid=? AND B.name=? AND A.realmoney=? AND A.status=0 AND A.addtime>=DATE_SUB(NOW(), INTERVAL 5 MINUTE) ORDER BY A.addtime DESC LIMIT 1",
            [$channel['id'], $payId, $type, $price]
        );

        if (empty($matchOrder)) {
            exit('fail - order not found');
        }

        $matchOrder['plugin'] = $channel['plugin'];
        processNotify($matchOrder, $payId . '_' . time());

        exit('success');
    }

    // 同步返回
    static public function return(): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }
}
