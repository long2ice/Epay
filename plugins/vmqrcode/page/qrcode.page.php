<?php
if (!defined('IN_PLUGIN')) exit();
$paytime = strtotime($order['addtime']) + 300 - time();
$payname = $order['typename'] == 'wxpay' ? '微信' : '支付宝';
?>
<!DOCTYPE html>
<html>

<head>
    <meta name="viewport" content="initial-scale=1, maximum-scale=1, user-scalable=no, width=device-width">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="renderer" content="webkit">
    <title><?php echo $payname ?>扫码支付</title>
    <link href="/assets/css/alipay_pay.css?v=3" rel="stylesheet" media="screen">
    <style>
        .amount {
            font-size: 36px;
            color: #e8501c;
            font-weight: bold;
            text-align: center;
            margin: 15px 0;
        }
        .amount-tip {
            text-align: center;
            color: #999;
            font-size: 13px;
            margin-bottom: 10px;
        }
        .qr-image {
            text-align: center;
            margin: 15px auto;
        }
        .qr-image img {
            max-width: 230px;
            max-height: 230px;
            border: 1px solid #eee;
            border-radius: 4px;
        }
        .countdown-container {
            margin: 15px auto;
            text-align: center;
        }
        .countdown-title {
            font-size: 16px;
            color: #e8501c;
            margin-bottom: 10px;
        }
        .countdown-timer {
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .countdown-box {
            background-color: #1E9FFF;
            color: white;
            border-radius: 4px;
            padding: 5px 8px;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .countdown-box span {
            font-size: 18px;
            font-weight: bold;
        }
        .countdown-label {
            margin-left: 4px;
            font-size: 14px;
        }
        .qr-expired-overlay {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 10;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .qr-expired-text {
            color: #fff;
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .qr-expired-icon {
            font-size: 40px;
            color: #FF5722;
            margin-bottom: 10px;
        }
        .qr-container {
            position: relative;
            display: inline-block;
            margin: 0 auto;
        }
    </style>
</head>

<body>
    <div class="body">
        <h1 class="mod-title">
            <span class="text"><?php echo $payname ?>扫码支付</span>
        </h1>
        <div class="mod-ct">
            <div class="amount-tip">请使用<?php echo $payname ?>扫描下方二维码</div>
            <div class="amount">￥<?php echo $amount ?></div>
            <div class="amount-tip">请务必按照上方金额精确转账，否则无法自动到账</div>
            <div class="qr-container" style="margin-top: 15px;">
                <div class="qr-image">
                    <img src="<?php echo htmlspecialchars($qrcode_url) ?>" alt="收款二维码">
                </div>
                <div class="qr-expired-overlay" id="qrExpiredOverlay">
                    <div class="qr-expired-icon">×</div>
                    <div class="qr-expired-text">订单已超时</div>
                    <div class="qr-expired-text">请返回重新发起支付</div>
                </div>
            </div>
            <!-- 倒计时 -->
            <div class="countdown-container">
                <div class="countdown-title">支付剩余时间</div>
                <div class="countdown-timer">
                    <div class="countdown-box">
                        <span id="minutes">00</span>
                        <span class="countdown-label">分</span>
                    </div>
                    <div class="countdown-box">
                        <span id="seconds">00</span>
                        <span class="countdown-label">秒</span>
                    </div>
                </div>
            </div>
            <div class="mobile_tip" style="display: none;">
                <div style="text-align:center;color:#e8501c;font-size:15px;margin:15px 0 5px;">请截图保存上方二维码，打开<?php echo $payname ?>扫一扫完成支付</div>
                <a onclick="checkresult()" class="btn-check">我已付款，返回查看订单</a>
            </div>
            <div class="detail" id="orderDetail" style="margin-top: 0px;">
                <dl class="detail-ct" style="display: none;">
                    <dt>购买物品</dt>
                    <dd><?php echo htmlspecialchars($order['name']) ?></dd>
                    <dt>商户订单号</dt>
                    <dd><?php echo $order['trade_no'] ?></dd>
                    <dt>创建时间</dt>
                    <dd><?php echo $order['addtime'] ?></dd>
                </dl>
                <a href="javascript:void(0)" class="arrow"><i class="ico-arrow"></i></a>
            </div>
            <div class="tip">
                <span class="dec dec-left"></span>
                <span class="dec dec-right"></span>
                <div class="ico-scan"></div>
                <div class="tip-text">
                    <p>请使用<?php echo $payname ?>扫一扫</p>
                    <p>扫描二维码完成支付</p>
                </div>
            </div>
        </div>
        <script src="<?php echo $cdnpublic ?>jquery/1.12.4/jquery.min.js"></script>
        <script src="<?php echo $cdnpublic ?>layer/3.1.1/layer.js"></script>
        <script>
            // 订单详情
            $('#orderDetail .arrow').click(function() {
                if ($('#orderDetail').hasClass('detail-open')) {
                    $('#orderDetail .detail-ct').slideUp(500, function() {
                        $('#orderDetail').removeClass('detail-open');
                    });
                } else {
                    $('#orderDetail .detail-ct').slideDown(500, function() {
                        $('#orderDetail').addClass('detail-open');
                    });
                }
            });

            // 轮询支付状态
            function loadmsg() {
                $.ajax({
                    type: "GET",
                    dataType: "json",
                    url: "/getshop.php",
                    data: {
                        type: "<?php echo $order['typename'] ?>",
                        trade_no: "<?php echo $order['trade_no'] ?>"
                    },
                    success: function(data) {
                        if (data.code == 1) {
                            alert('支付成功，正在跳转中...');
                            window.location.href = data.backurl;
                        } else {
                            setTimeout("loadmsg()", 3000);
                        }
                    },
                    error: function() {
                        setTimeout("loadmsg()", 3000);
                    }
                });
            }

            function checkresult() {
                $.ajax({
                    type: "GET",
                    dataType: "json",
                    url: "/getshop.php",
                    data: {
                        type: "<?php echo $order['typename'] ?>",
                        trade_no: "<?php echo $order['trade_no'] ?>"
                    },
                    success: function(data) {
                        if (data.code == 1) {
                            alert('支付成功，正在跳转中...');
                            window.location.href = data.backurl;
                        } else {
                            alert('您还未完成付款，请继续付款');
                        }
                    },
                    error: function() {
                        alert('服务器错误');
                    }
                });
            }

            var isMobile = function() {
                var ua = navigator.userAgent;
                var ipad = ua.match(/(iPad).*OS\s([\d_]+)/),
                    isIphone = !ipad && ua.match(/(iPhone\sOS)\s([\d_]+)/),
                    isAndroid = ua.match(/(Android)\s+([\d.]+)/);
                return isIphone || isAndroid;
            }

            window.onload = function() {
                if (isMobile()) {
                    $('.mobile_tip').show();
                }
                setTimeout("loadmsg()", 3000);
                startCountdown(<?php echo $paytime; ?>);
            }

            // 倒计时
            function startCountdown(duration) {
                var timer = duration;
                var minutesElement = document.getElementById('minutes');
                var secondsElement = document.getElementById('seconds');
                var qrExpiredOverlay = document.getElementById('qrExpiredOverlay');
                if (duration <= 0) {
                    qrExpiredOverlay.style.display = 'flex';
                    return;
                }
                var countdown = function() {
                    var minutes = Math.floor(timer / 60);
                    var seconds = timer % 60;
                    minutesElement.textContent = minutes < 10 ? "0" + minutes : minutes;
                    secondsElement.textContent = seconds < 10 ? "0" + seconds : seconds;
                    if (--timer < 0) {
                        clearInterval(window.countdownInterval);
                        qrExpiredOverlay.style.display = 'flex';
                    }
                }
                countdown();
                window.countdownInterval = setInterval(countdown, 1000);
            }
        </script>
</body>

</html>
