<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);

// 获取搜索关键词
$msg = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$debug = isset($_GET['debug']) ? $_GET['debug'] : ''; // debug=raw 查看原始数据

if (empty($msg)) {
    echo json_encode([
        'code' => 400,
        'msg' => '请提供搜索关键词',
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 初始化结果数组
$allEvents = [];

// 获取第一页数据
$firstPageResponse = fetchPage($msg, 1);

if ($firstPageResponse === false) {
    echo json_encode([
        'code' => 500,
        'msg' => '获取数据失败',
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 特殊调试：查看原始响应
if ($debug === 'response') {
    echo json_encode([
        'code' => 200,
        'msg' => '原始响应（debug模式）',
        'response_length' => strlen($firstPageResponse),
        'response' => $firstPageResponse
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 解析JSON响应
$firstPageData = json_decode($firstPageResponse, true);

if ($firstPageData === null || !isset($firstPageData['result']['list'])) {
    echo json_encode([
        'code' => 500,
        'msg' => 'JSON解析失败',
        'data' => [],
        'debug_info' => $firstPageData
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 如果是调试模式，返回原始JSON数据
if ($debug === 'raw') {
    echo json_encode([
        'code' => 200,
        'msg' => '原始数据（debug模式）',
        'total' => isset($firstPageData['result']['total']) ? (int)$firstPageData['result']['total'] : 0,
        'raw_data' => $firstPageData['result']['list']
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 解析第一页数据
$events = parseEvents($firstPageData['result']['list']);
$allEvents = array_merge($allEvents, $events);

// 计算总页数（每页10条）
$total = isset($firstPageData['result']['total']) ? (int)$firstPageData['result']['total'] : 0;
$totalPages = ceil($total / 10);

// 获取剩余页面数据
for ($page = 2; $page <= $totalPages; $page++) {
    $pageResponse = fetchPage($msg, $page);
    if ($pageResponse !== false) {
        $pageData = json_decode($pageResponse, true);
        if ($pageData !== null && isset($pageData['result']['list'])) {
            $events = parseEvents($pageData['result']['list']);
            $allEvents = array_merge($allEvents, $events);
        }
    }
    usleep(300000); // 延迟0.3秒
}

// 按时间排序（从近到远，时间越小越靠前）
usort($allEvents, function($a, $b) {
    $timeA = !empty($a['time']) ? strtotime($a['time']) : PHP_INT_MAX;
    $timeB = !empty($b['time']) ? strtotime($b['time']) : PHP_INT_MAX;
    return $timeA - $timeB;
});

// 返回结果
echo json_encode([
    'code' => 200,
    'msg' => $msg,
    'data' => $allEvents
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

/**
 * 获取指定页面的数据
 */
function fetchPage($search, $page) {
    // 构建GET请求URL
    $params = [
        'time' => 8,
        'sort' => 1,
        'keyword' => $search,
        'pageNo' => $page,
        'pageSize' => 10
    ];
    
    $url = 'https://www.allcpp.cn/allcpp/event/eventMainListV2.do?' . http_build_query($params);
    
    $headers = [
        'Host: www.allcpp.cn',
        'Connection: keep-alive',
        'Accept: */*',
        'errorWrap: json',
        'Origin: https://cp.allcpp.cn',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-site',
        'Sec-Fetch-Dest: empty',
        'Referer: https://cp.allcpp.cn/',
        'Accept-Encoding: gzip, deflate, br',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8,en-GB;q=0.7,en-US;q=0.6',
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return false;
    }
    
    return $response;
}

/**
 * 解析事件数据（从JSON）
 */
function parseEvents($eventList) {
    $events = [];
    
    if (empty($eventList) || !is_array($eventList)) {
        return $events;
    }
    
    foreach ($eventList as $item) {
        // 检查是否取消 (enabled: 5 代表取消)
        $isCancelled = false;
        if (isset($item['enabled']) && $item['enabled'] == 5) {
            $isCancelled = true;
        }
        
        // 处理名称，如果取消则添加标记
        $name = isset($item['name']) ? $item['name'] : '';
        
        // 添加取消标记
        if ($isCancelled && strpos($name, '(已取消)') === false) {
            $name .= '(已取消)';
        }
        
        // 添加进行中或倒计时标记
        if (!$isCancelled) {
            $statusTag = getEventStatusTag($item);
            if ($statusTag && strpos($name, $statusTag) === false) {
                $name .= $statusTag;
            }
        }
        
        $event = [
            'id' => isset($item['id']) ? (int)$item['id'] : 0,
            'name' => $name,
            'tag' => isset($item['tag']) ? $item['tag'] : '',
            'location' => parseLocation($item),
            'address' => isset($item['enterAddress']) ? $item['enterAddress'] : '',
            'url' => isset($item['id']) ? "https://www.allcpp.cn/allcpp/event/event.do?event=" . $item['id'] : '',
            'type' => isset($item['type']) ? $item['type'] : '综合展',
            'wannaGoCount' => isset($item['wannaGoCount']) ? (int)$item['wannaGoCount'] : 0,
            'circleCount' => isset($item['circleCount']) ? (int)$item['circleCount'] : 0,
            'doujinshiCount' => isset($item['doujinshiCount']) ? (int)$item['doujinshiCount'] : 0,
            'time' => parseTime($item),
            'appLogoPicUrl' => parseImageUrl($item, 'appLogoPicUrl'),
            'logoPicUrl' => parseImageUrl($item, 'logoPicUrl'),
            'ended' => parseEnded($item),
            'isOnline' => isset($item['isOnline']) ? ($item['isOnline'] == 1 ? '线上' : '线下') : '线下'
        ];
        
        $events[] = $event;
    }
    
    return $events;
}

/**
 * 获取展会状态标签（进行中或倒计时）
 */
function getEventStatusTag($item) {
    if (!isset($item['enterTime'])) {
        return '';
    }
    
    // 设置时区为 UTC+8
    date_default_timezone_set('Asia/Shanghai');
    
    $enterTimestamp = $item['enterTime'] / 1000;
    $endTimestamp = isset($item['endTime']) ? $item['endTime'] / 1000 : $enterTimestamp;
    
    $now = time();
    $today = strtotime(date('Y-m-d 00:00:00'));
    $tomorrow = strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')));
    
    $enterDate = strtotime(date('Y-m-d 00:00:00', $enterTimestamp));
    $endDate = strtotime(date('Y-m-d 23:59:59', $endTimestamp));
    
    // 如果展会已经结束
    if ($now > $endDate) {
        return '';
    }
    
    // 如果展会正在进行中（今天在展会日期范围内）
    if ($today >= $enterDate && $today <= strtotime(date('Y-m-d 00:00:00', $endTimestamp))) {
        return '(进行中)';
    }
    
    // 如果展会还未开始，计算剩余天数
    if ($now < $enterTimestamp) {
        $daysLeft = ceil(($enterDate - $today) / 86400);
        if ($daysLeft > 0) {
            return "(还有{$daysLeft}天开始)";
        }
    }
    
    return '';
}

/**
 * 解析地点信息
 */
function parseLocation($item) {
    $location = '';
    
    if (isset($item['provName'])) {
        $location .= $item['provName'];
    }
    
    if (isset($item['cityName'])) {
        $location .= ' ' . $item['cityName'];
    }
    
    if (isset($item['areaName'])) {
        $location .= ' ' . $item['areaName'];
    }
    
    return trim($location);
}

/**
 * 解析类型
 */
function parseType($item) {
    if (isset($item['evmtype'])) {
        $typeMap = [
            0 => '综合展',
            1 => 'ONLY',
            2 => '茶会',
            3 => '漫展',
        ];
        return isset($typeMap[$item['evmtype']]) ? $typeMap[$item['evmtype']] : '其他';
    }
    
    // 从标签中猜测类型
    if (isset($item['tag'])) {
        $tag = strtoupper($item['tag']);
        if (strpos($tag, 'ONLY') !== false) {
            return 'ONLY';
        }
        if (strpos($tag, '茶会') !== false || strpos($tag, '茶话会') !== false) {
            return '茶会';
        }
        if (strpos($tag, '综合展') !== false) {
            return '综合展';
        }
    }
    
    return '综合展';
}

/**
 * 解析时间
 */
function parseTime($item) {
    if (isset($item['enterTime'])) {
        // enterTime是时间戳（毫秒）
        $timestamp = $item['enterTime'] / 1000;
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    if (isset($item['startTime'])) {
        return $item['startTime'];
    }
    
    return '';
}

/**
 * 解析图片URL
 */
function parseImageUrl($item, $field) {
    $url = '';
    
    if (isset($item[$field])) {
        $url = $item[$field];
        
        // 如果是相对路径，添加CDN域名前缀
        if (!empty($url) && strpos($url, 'http') !== 0) {
            $url = 'https://imagecdn3.allcpp.cn/upload' . $url;
        }
    }
    
    // 如果appLogoPicUrl为空，尝试从logoPicUrl生成
    if ($field === 'appLogoPicUrl' && empty($url) && isset($item['logoPicUrl'])) {
        $url = preg_replace('/\?.*$/', '', $item['logoPicUrl']);
    }
    
    return $url;
}

/**
 * 判断是否结束
 */
function parseEnded($item) {
    // 设置时区为 UTC+8
    date_default_timezone_set('Asia/Shanghai');
    
    // 优先判断 enabled 状态
    if (isset($item['enabled'])) {
        if ($item['enabled'] == 1) {
            return '已结束';
        } else if ($item['enabled'] == 2) {
            return '筹备中';
        } else if ($item['enabled'] == 5) {
            return '已取消';
        }
    }
    
    // 根据结束时间判断
    if (isset($item['endTime']) && $item['endTime'] > 0) {
        $endTimestamp = $item['endTime'] / 1000;
        $endDate = strtotime(date('Y-m-d 23:59:59', $endTimestamp));
        
        if (time() > $endDate) {
            return '已结束';
        }
    }
    
    // 判断是否为筹备中（ended字段）
    if (isset($item['ended']) && $item['ended'] === true) {
        return '已结束';
    }
    
    return '未结束';
}
?>