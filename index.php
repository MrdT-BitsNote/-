<?php
/**
 * ============================================================
 *  鎏光MrdT相册 · Liuguang MrdT Gallery
 *  单文件 PHP 相册 · 无需数据库 · 无需安装
 * ------------------------------------------------------------
 *  用法：把本文件放到任意包含"图片子文件夹"的目录，
 *        浏览器打开即用。
 *
 *  ── 功能（整合 Lychee / Piwigo / FilePress 各自优点）──
 *   [分类]  自动扫描子文件夹成相册；根目录散图归「未分类」
 *   [视图]  瀑布流 / 等高网格（按行对齐，不裁图）/ 时间轴杂志 三视图自由切换
 *   [模式]  按相册分区 / 全部照片平铺 双模式
 *   [检索]  实时搜索（文件名 + 相册名）
 *   [排序]  名称 / 体积 × 升序 / 降序；时间排序仅时间轴视图提供
 *   [开场]  首次打开 2.4s 鎏光旋转光环，期间并发拉满、首屏缩略图全速预载
 *   [收藏]  卡片一键收藏，localStorage 持久化，可只看收藏
 *   [灯箱]  左右切换、键盘、触屏滑动、1:1 缩放
 *   [信息]  EXIF 拍摄参数（相机/镜头/光圈/快门/ISO/焦距/GPS）
 *           + 文件元数据（尺寸/体积/修改时间/相对路径）
 *   [时间轴] 按日期分组的杂志式长列表：日期大字 + 左侧导轨 + 弹性入场，
 *           图片按原始比例居中，拍摄参数直接铺在照片下方（批量拉取，一次一屏）
 *   [视频]  mp4 / webm / mov / m4v / ogv：封面帧 + 时长角标 + 灯箱原生播放。
 *           有 ffmpeg → 抽帧做封面（与图片缩略图同一套缓存与原子写入）；
 *           没 ffmpeg → mp4/mov 用纯 PHP 读 moov 拿时长/尺寸/编码，
 *           卡片显示占位封面，播放照常（由 nginx 直出，支持 206 分段）
 *   [动图]  GIF / APNG / 动态 WebP：纯 PHP 解析块结构判定动画（只读几 KB），
 *           卡片永远是静态首帧 + 「动图」角标（悬停即播，仅悬停那一张）；
 *           灯箱按服务器条件二选一 —— 有 ffmpeg 转成 MP4 播放（体积约降至 1/10，
 *           且复用视频那套缓存与 Range 分段），没 ffmpeg 直接播原图（动画零损耗）。
 *           含透明通道的动图不转码（避免透明区变黑），直接播原图。
 *   [实况]  iPhone Live Photo（HEIC/JPG + 同名 MOV）自动配对成一条记录：
 *           MOV 不再单独成卡；HEIC 用配对 MOV 抽首帧当封面（绕开 GD 无 HEIC 解码）；
 *           卡片带 LIVE 徽标，灯箱里按住播放、松手回到静帧，紧贴 iOS 原生手感。
 *   [格式]  JPEG / JFIF / PNG / GIF / WebP / AVIF / BMP / HEIC / HEIF 全格式；
 *           GD 解不了的（HEIC、动态 WebP、裁剪版 GD 的 AVIF）由 ffmpeg 抽帧兜底
 *   [分享]  一键下载原图 · 一键复制直链
 *   [品牌]  鎏金流光视觉 · 品牌徽标 · 顶部加载光条
 *
 *  ── 可靠性（保持不降级）──
 *   WebP 自动编码 + 格式协商（体积比 JPEG 小约 30%）
 *   原子写入（临时文件 + rename），杜绝并发半张坏图
 *   memory_limit 按需提升，超大图不再崩
 *   有序并发加载器：相册封面优先 → 视口顺序 → 并发上限
 *   三级降级链：缩略图 → 原图 → 自适应占位纹（视频→占位封面）
 *   骨架屏 + 预置宽高，零布局抖动（视频尺寸由 ffprobe/纯 PHP 解析预置）
 *   CLI 预热 + 页面触发后台预热（fastcgi_finish_request）
 *
 *  兼容：PHP 7.4 ~ 8.5；无 GD 时自动退化为原图直出；无 ffmpeg 时视频照常播放
 * ============================================================
 */

/* ───── 配置 ───── */
$CONFIG = [
    'brand'        => '鎏光MrdT相册',            // 品牌名（页面标题 / 顶栏 / 页脚）
    'brand_short'  => '鎏光MrdT',                // 短名
    'desc'         => '单文件驱动的本地相册 · 瀑布流 · 原图可下载',  // 站点描述
    'cache_dir'    => __DIR__ . '/.cache',       // 缩略图缓存目录
    'thumb_w'      => 640,                       // 卡片缩略图宽
    'view_w'       => 1600,                      // 灯箱大图宽
    'quality'      => 82,                        // 缩略图压缩质量
    'allowed_ext'  => ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'avif', 'bmp', 'heic', 'heif'],
    'video_ext'    => ['mp4', 'webm', 'mov', 'm4v', 'ogv'],   // 视频扩展名
    'anim_ext'     => ['gif', 'png', 'webp'],                 // 可能含多帧动画的格式
    'live_still'   => ['jpg', 'jpeg', 'heic', 'heif'],        // 可与同名 MOV 配成实况照片的静帧
    'live_movie'   => 'mov',                                  // 实况照片的动态部分扩展名
    'anim_max_sec' => 60,                        // 动图超过此秒数不转 MP4（保护低配服务器）
    'ffmpeg'       => '',                        // ffmpeg 路径；留空 = 自动探测（软件商店装完无需填写）
    'ffprobe'      => '',                        // ffprobe 路径；留空 = 自动探测（通常与 ffmpeg 同目录）
    'per_page'     => 0,                         // 0 = 不分页（全量输出）
    'extra_roots'  => [],                        // 额外允许的媒体根（绝对路径）；桌面 App 用符号链接挂外部图库时需要
];

/* ───── 环境变量覆盖（桌面 App / 容器部署用；网页部署无需理会） ─────
 *   GALLERY_CACHE_DIR    覆盖 cache_dir
 *   GALLERY_FFMPEG       覆盖 ffmpeg 路径
 *   GALLERY_FFPROBE      覆盖 ffprobe 路径
 *   GALLERY_EXTRA_ROOTS  额外媒体根，多个用 PATH 分隔符（: 或 ;）分隔 */
foreach (['cache_dir' => 'GALLERY_CACHE_DIR', 'ffmpeg' => 'GALLERY_FFMPEG',
          'ffprobe'  => 'GALLERY_FFPROBE'] as $ck => $env) {
    $v = getenv($env);
    if (is_string($v) && $v !== '') $CONFIG[$ck] = $v;
}
$xr = getenv('GALLERY_EXTRA_ROOTS');
if (is_string($xr) && $xr !== '') {
    $CONFIG['extra_roots'] = array_values(array_filter(
        array_map('trim', explode(PATH_SEPARATOR, $xr)), 'strlen'));
}

/* ───── 基础工具 ───── */

/** 取扩展名并小写化（判断类型一律走这里，杜绝 JPG/jpg 分叉） */
function ext_of($name) {
    return strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
}

/** 是否受支持的图片扩展名（$allowed 即 allowed_ext 清单） */
function is_image($name, $allowed) {
    return in_array(ext_of($name), $allowed, true);
}

/** 视频扩展名判定 */
function is_video($name) {
    global $CONFIG;
    return in_array(ext_of($name), $CONFIG['video_ext'], true);
}

/** 可能含多帧动画的图片格式（GIF / APNG / 动态 WebP）——是否需要探测由 anim_cached 决定 */
function is_anim_ext($name) {
    global $CONFIG;
    return in_array(ext_of($name), $CONFIG['anim_ext'], true);
}

/** 可与同名 MOV 配成实况照片的静帧扩展名 */
function is_live_still($name) {
    global $CONFIG;
    return in_array(ext_of($name), $CONFIG['live_still'], true);
}

/** HEIC / HEIF：GD 解不了，需要 ffmpeg 才能出封面（或借配对 MOV 抽帧） */
function is_heic($name) {
    $e = ext_of($name);
    return $e === 'heic' || $e === 'heif';
}

/** GD 这条链路解不开、但 ffmpeg 能抽首帧的图（HEIC / 动图 / 无 avif 解码的 AVIF） */
function needs_ffmpeg_frame($abs) {
    if (is_heic($abs) || is_anim($abs)) return true;
    return ext_of($abs) === 'avif' && !function_exists('imagecreatefromavif');
}

/** 受支持的媒体（图片或视频）——扫描、路径校验统一走这里 */
function is_media($name) {
    global $CONFIG;
    return is_image($name, $CONFIG['allowed_ext']) || is_video($name);
}

/** 小写化（不依赖 mbstring） */
function lower($s) {
    return function_exists('mb_strtolower')
        ? mb_strtolower((string)$s, 'UTF-8')
        : strtolower((string)$s);
}

/** base64url 编/解码：媒体相对路径的 URL 安全形态（?t= ?a= ?x= 接口的 key） */
function b64url_enc($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_dec($s) { return base64_decode(strtr($s, '-_', '+/')); }

/** HTML 转义（所有输出到页面的用户文件名一律过它，防 XSS） */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 人类可读体积 */
function human_size($b) {
    $b = (float)$b;
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return round($b / 1024) . ' KB';
    return (int)$b . ' B';
}

/** 相对 __DIR__ 的路径（统一 / 分隔） */
function rel_of($path) {
    return str_replace('\\', '/', substr($path, strlen(__DIR__) + 1));
}

/** 原图直链（逐段 rawurlencode，兼容中文/空格/#） */
function orig_url($rel) {
    $parts = explode('/', $rel);
    foreach ($parts as &$p) { $p = rawurlencode($p); }
    unset($p);
    return implode('/', $parts);
}

/** 递归收集某目录下的全部媒体文件（跳过 . 开头的隐藏文件/目录，如 .cache、.git） */
function collect_images($dir, $allowed, &$out) {
    $entries = @scandir($dir);
    if (!$entries) return;
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || $e[0] === '.') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $e;
        if (is_dir($p))                 { collect_images($p, $allowed, $out); }
        elseif (is_file($p) && is_media($e)) { $out[] = $p; }
    }
}

/** 扫描根目录：一级子文件夹为相册，根目录散图为「未分类」 */
function scan_albums($allowed) {
    $albums  = [];
    $entries = @scandir(__DIR__);
    if (!$entries) return $albums;
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || $e[0] === '.') continue;
        if ($e === basename(__FILE__)) continue;
        $p = __DIR__ . DIRECTORY_SEPARATOR . $e;
        if (is_dir($p)) {
            $files = [];
            collect_images($p, $allowed, $files);
            if ($files) { $albums[$e] = $files; }
        } elseif (is_file($p) && is_media($e)) {
            // 必须存绝对路径：下游 rel_of()/generate_thumb()/orig_url() 全部按绝对路径推导，
            // 历史上这里存过裸文件名，导致根目录散图的缩略图 404、data-key 全为空值撞车。
            $albums['未分类'][] = $p;
        }
    }
    // 相册内统一自然序（卡片渲染与灯箱索引必须共用同一顺序）
    foreach ($albums as $k => $files) {
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        $albums[$k] = $files;
    }
    uksort($albums, 'strnatcasecmp');
    if (isset($albums['未分类'])) {                   // 未分类永远排最后
        $v = $albums['未分类']; unset($albums['未分类']); $albums['未分类'] = $v;
    }
    return $albums;
}

/**
 * 实况照片（iPhone Live Photo）配对表。
 * 导出形态是「静态照片 + 同名 MOV」两个文件；不配对的话照片会碎成两条记录
 * （HEIC 不可见、MOV 单独成卡）。这里在同一个目录内按主文件名配对。
 *
 * 返回 ['pair' => [静帧绝对路径 => MOV 绝对路径], 'drop' => [MOV 绝对路径 => 1]]
 * drop 里的 MOV 不再单独成卡 —— 它的动态由配对的那张照片承载。
 */
function live_pairs($albums = null) {
    global $CONFIG;
    static $r = null;
    if ($r !== null) return $r;
    if ($albums === null) $albums = scan_albums($CONFIG['allowed_ext']);
    $mov  = $CONFIG['live_movie'];
    $r    = ['pair' => [], 'drop' => []];
    foreach ($albums as $files) {
        // 配对键 = 所在目录 + "\0" + 小写主文件名（去掉扩展名）。
        // 同目录同主名才配：IMG_0421.HEIC ↔ IMG_0421.MOV；\0 是不会出现在路径里的分隔符。
        $g = [];
        foreach ($files as $p) {
            if (ext_of($p) === $mov) {
                $g[dirname($p) . "\0" . lower(substr(basename($p), 0, -strlen($mov) - 1))]['m'] = $p;
            } elseif (is_live_still($p)) {
                // 同名的静帧可能不止一张（IMG_0421.jpg 与 IMG_0421.HEIC 同时存在，
                // 常见于"手机导出 HEIC + 电脑转存 JPG"）。全部收下、全部配对，
                // 否则落选的那张会既没有 LIVE 角标、又拿不到 MOV 首帧当封面
                // ——真 HEIC 浏览器根本显示不了，卡片就是一片空白。
                $g[dirname($p) . "\0" . lower(substr(basename($p), 0, -strlen(ext_of($p)) - 1))]['s'][] = $p;
            }
        }
        foreach ($g as $set) {
            if (empty($set['m']) || empty($set['s'])) continue;
            foreach ($set['s'] as $s) $r['pair'][$s] = $set['m'];
            $r['drop'][$set['m']] = 1;                 // 动态部分不再单独成卡
        }
    }
    return $r;
}

/** 某张静帧配对的 MOV 绝对路径；没有配对返回 '' */
function live_pair($abs) {
    $l = live_pairs();
    return isset($l['pair'][$abs]) ? $l['pair'][$abs] : '';
}

/** 尺寸清单缓存：避免每次请求都对全量图片做 getimagesize。
 *  按引用返回（&）：img_dims 探测到新尺寸后直接写入这份内存表，
 *  请求结束时由 save_manifest 一次性落盘（manifest.json）。 */
function &manifest() {
    global $CONFIG;
    static $m = null;
    if ($m !== null) return $m;
    $file = $CONFIG['cache_dir'] . '/manifest.json';
    $m = [];
    if (is_file($file)) {
        $j = json_decode((string)@file_get_contents($file), true);
        if (is_array($j) && isset($j['files']) && is_array($j['files'])) $m = $j['files'];
    }
    return $m;
}

/** manifest 落盘（覆盖式写整个清单；体积小，无须原子写入） */
function save_manifest($m) {
    global $CONFIG;
    if (!is_dir($CONFIG['cache_dir'])) @mkdir($CONFIG['cache_dir'], 0755, true);
    @file_put_contents($CONFIG['cache_dir'] . '/manifest.json',
        json_encode(['files' => $m], JSON_UNESCAPED_UNICODE));
}

/** 该图在展示时是否需要把宽高对调（EXIF 方向 6/8 = 手机竖拍） */
function exif_swapped($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext !== 'jpg' && $ext !== 'jpeg') return false;      // 只有 JPEG 有常见的方向标记
    if (!function_exists('exif_read_data')) return false;
    $e = @exif_read_data($path, 'IFD0');
    if (!$e || empty($e['Orientation'])) return false;
    $o = (int)$e['Orientation'];
    return $o === 6 || $o === 8;
}

/** 返回 [w,h]，带 manifest 缓存 */
function img_dims($path) {
    $rel   = rel_of($path);
    $m     = &manifest();
    $mtime = @filemtime($path);
    if (isset($m[$rel]) && isset($m[$rel]['m']) && $m[$rel]['m'] == $mtime) {
        return [(int)$m[$rel]['w'], (int)$m[$rel]['h']];
    }
    // 视频：必须由 ffprobe / 纯 PHP 解析给出真实尺寸，否则瀑布流与网格的比例全是错的。
    // 探测不到（如无 ffprobe 的 webm）就按 16:9 固定占位 —— 宁可比例不准，也不让版面跳动。
    if (is_video($path)) {
        $p = probe_cached($path);
        $w = (int)($p['w'] ?? 0);
        $h = (int)($p['h'] ?? 0);
        if ($w < 1 || $h < 1) { $w = 1280; $h = 720; }
        $m[$rel] = ['m' => $mtime, 'w' => $w, 'h' => $h];
        return [$w, $h];
    }
    $info = @getimagesize($path);
    $w = $info ? (int)$info[0] : 0;
    $h = $info ? (int)$info[1] : 0;
    // HEIC / HEIF：getimagesize 与 GD 都读不了。实况照片借配对 MOV 的视频尺寸当占位比例
    // （iOS 的 MOV 与静帧同比例），比例对了、图片就绪后版面不跳。
    if (($w < 1 || $h < 1) && is_heic($path)) {
        $pair = live_pair($path);
        if ($pair !== '') {
            $pv = probe_cached($pair);
            $w  = (int)($pv['w'] ?? 0);
            $h  = (int)($pv['h'] ?? 0);
        }
    }
    // getimagesize 给的是未旋转尺寸，而缩略图在生成时已按 EXIF 摆正；
    // 这里同步对调，卡片才会拿"展示时的真实比例"占位，图片就绪后不再跳动
    // （等高网格的每行对齐也依赖这个比例算宽度）。
    if ($w && $h && exif_swapped($path)) { $t = $w; $w = $h; $h = $t; }
    if ($w && $h) { $m[$rel] = ['m' => $mtime, 'w' => $w, 'h' => $h]; }
    return [$w, $h];
}

/** GD 解码：按扩展名选 imagecreatefrom*。
 *  webp / avif / bmp 在某些编译里没有对应函数，先 function_exists 再调；
 *  动态 WebP、HEIC 在这里必然失败 —— 调用方（generate_thumb）会退到 ffmpeg 抽帧。 */
function gd_load($path, $ext) {
    switch ($ext) {
        case 'jpg': case 'jpeg':
        case 'jfif':             return @imagecreatefromjpeg($path);
        case 'png':              return @imagecreatefrompng($path);
        case 'gif':              return @imagecreatefromgif($path);
        case 'webp':             return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        case 'avif':             return function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false;  // GD ≥ 8.1 编译进 avif 才有
        case 'bmp':              return function_exists('imagecreatefrombmp')  ? @imagecreatefrombmp($path)  : false;
    }
    return false;
}

/** 按 EXIF 方向校正（手机竖拍） */
function exif_fix(&$img, $path) {
    if (!function_exists('exif_read_data')) return;
    $exif = @exif_read_data($path);
    if (!$exif || empty($exif['Orientation'])) return;
    $o = (int)$exif['Orientation'];
    if ($o === 3)     { $img = imagerotate($img, 180, 0); }
    elseif ($o === 6) { $img = imagerotate($img, -90, 0); }
    elseif ($o === 8) { $img = imagerotate($img, 90, 0);  }
}

/* ───── EXIF / 文件元数据读取（供 ?x= 接口） ───── */

/** 分数/字符串转浮点，如 "28/10" → 2.8 */
function frac($v) {
    if (is_string($v) && strpos($v, '/') !== false) {
        $p = explode('/', $v);
        if (count($p) === 2 && (float)$p[1] != 0) return (float)$p[0] / (float)$p[1];
        return 0.0;
    }
    return (float)$v;
}

/** 跨 section 取 EXIF 字段 */
function exif_pick($e, $key) {
    foreach (['IFD0', 'EXIF', 'COMPUTED', 'FILE', 'GPS'] as $sec) {
        if (isset($e[$sec][$key]) && $e[$sec][$key] !== '' && $e[$sec][$key] !== null) {
            return $e[$sec][$key];
        }
    }
    return null;
}

/** GPS 度分秒 → 十进制度 */
function gps_dec($coord, $ref) {
    if (!is_array($coord) || count($coord) < 3) return null;
    $v = frac($coord[0]) + frac($coord[1]) / 60 + frac($coord[2]) / 3600;
    if ($ref === 'S' || $ref === 'W') $v = -$v;
    return round($v, 6);
}

/**
 * 读取拍摄参数。返回 [键 => 值] 的有序数组（只保留有值的项）。
 * 视频走另一个分支（ffprobe / 纯 PHP 解析 mp4），键值结构保持一致。
 * 说明：部分手机导出的图无 EXIF；微信/截图类图片通常也没有。
 */
function read_meta($abs) {
    if (is_video($abs)) return read_video_meta($abs);
    $rows = [];
    if (!function_exists('exif_read_data')) return $rows;
    $e = @exif_read_data($abs, 'FILE,COMPUTED,IFD0,EXIF,GPS', true);
    if (!$e || !is_array($e)) return $rows;

    // 相机
    $make  = exif_pick($e, 'Make');
    $model = exif_pick($e, 'Model');
    $cam   = trim(($make ? $make . ' ' : '') . ($model ?: ''));
    $cam   = trim(preg_replace('/\s+/', ' ', $cam));
    if ($cam) $rows['相机'] = $cam;

    // 镜头
    $lens = exif_pick($e, 'LensModel') ?: exif_pick($e, 'UndefinedTag:0xA434');
    if ($lens) $rows['镜头'] = trim((string)$lens);

    // 光圈
    $fn = exif_pick($e, 'FNumber');
    if ($fn !== null) { $f = frac($fn); if ($f > 0) $rows['光圈'] = 'f/' . rtrim(rtrim(number_format($f, 1), '0'), '.'); }

    // 快门
    $et = exif_pick($e, 'ExposureTime');
    if ($et !== null) {
        $s = frac($et);
        if ($s > 0) $rows['快门'] = $s < 1 ? ('1/' . round(1 / $s) . ' s') : (rtrim(rtrim(number_format($s, 1), '0'), '.') . ' s');
    }

    // ISO
    $iso = exif_pick($e, 'ISOSpeedRatings');
    if (!$iso) $iso = exif_pick($e, 'PhotographicSensitivity');
    if ($iso) $rows['感光度'] = 'ISO ' . (is_array($iso) ? implode('/', $iso) : $iso);

    // 焦距
    $fl = exif_pick($e, 'FocalLength');
    if ($fl !== null) { $f = frac($fl); if ($f > 0) $rows['焦距'] = round($f) . ' mm'; }

    // 曝光补偿
    $ev = exif_pick($e, 'ExposureBiasValue');
    if ($ev !== null) { $v = frac($ev); if ($v != 0) $rows['曝光补偿'] = ($v > 0 ? '+' : '') . rtrim(rtrim(number_format($v, 1), '0'), '.') . ' EV'; }

    // 拍摄时间（EXIF 格式为 "YYYY:MM:DD HH:MM:SS"，需只替换日期段的冒号）
    $dt = exif_pick($e, 'DateTimeOriginal') ?: exif_pick($e, 'DateTime');
    if ($dt) {
        $s = trim((string)$dt);
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $s, $m)) {
            $ts = @mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
            $rows['拍摄时间'] = $ts ? date('Y-m-d H:i:s', $ts) : $s;
        } else {
            $rows['拍摄时间'] = $s;
        }
    }

    // 作者 / 版权
    if ($a = exif_pick($e, 'Artist'))    $rows['作者']   = trim((string)$a);
    if ($c = exif_pick($e, 'Copyright')) $rows['版权']   = trim((string)$c);

    // GPS
    $lat = isset($e['GPS']['GPSLatitude'])     ? gps_dec($e['GPS']['GPSLatitude'],  (string)($e['GPS']['GPSLatitudeRef']  ?? 'N')) : null;
    $lng = isset($e['GPS']['GPSLongitude'])    ? gps_dec($e['GPS']['GPSLongitude'], (string)($e['GPS']['GPSLongitudeRef'] ?? 'E')) : null;
    if ($lat !== null && $lng !== null && !($lat == 0 && $lng == 0)) {
        $rows['拍摄位置'] = number_format($lat, 6, '.', '') . ', ' . number_format($lng, 6, '.', '');
        if (!empty($e['GPS']['GPSAltitude'])) {
            $rows['海拔'] = round(frac($e['GPS']['GPSAltitude'])) . ' m';
        }
    }

    // 动图 / 实况照片的附加说明（灯箱信息面板与时间轴参数行共用这一份数据）
    if (is_anim($abs)) {
        $a = anim_cached($abs);
        $rows['动图'] = ($a['f'] > 1 ? $a['f'] . ' 帧' : '动画')
                      . (!empty($a['d']) ? ' · ' . fmt_ms($a['d']) : '')
                      . (anim_can_mp4($abs) ? ' · 转码播放'
                            : (!empty($a['ta']) ? ' · 原图画质（含透明）' : ' · 原图画质'));
    }
    $lp = live_pair($abs);
    if ($lp !== '') {
        $pv = probe_cached($lp);
        $rows['实况照片'] = '含动态' . (!empty($pv['dur']) ? ' ' . fmt_ms($pv['dur'] * 1000) : '')
                          . ' · ' . human_size(@filesize($lp));
    }
    return $rows;
}

/** 拍摄参数的磁盘缓存（原子写入）：时间轴视图一屏就是一二十张，
 *  没缓存的话每次刷新都要重解析一遍 EXIF。key 含 mtime + ffmpeg 是否就位
 *  （动图那行会写"转码播放/原图画质"，装了 ffmpeg 要自动重算）。 */
function meta_cached($abs) {
    global $CONFIG;
    $dir = $CONFIG['cache_dir'] . '/meta';
    $sig = (tool_path('ffmpeg') !== '') ? 'ff' : 'no';
    $f   = $dir . '/' . md5(rel_of($abs) . '|' . @filemtime($abs) . '|' . $sig) . '.json';
    if (is_file($f)) {
        $j = @json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) return $j;                 // [] 也是有效结果（该图无 EXIF）
    }
    $m = read_meta($abs);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $f . '.' . getmypid() . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, json_encode($m, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $f);
    return $m;
}

/* ═══════════ 视频支持 ═══════════
 * 分工（2H2G + 宝塔的务实口径）：
 *   ① 有 ffmpeg  → 抽一帧做封面，走与图片完全相同的 GD 缩放 + 原子写入缓存
 *   ② 有 ffprobe → 时长 / 尺寸 / 帧率 / 编码 / 码率，最准
 *   ③ 都没有     → mp4/mov/m4v 用纯 PHP 读 moov（mvhd/tkhd/stsd），只 seek 几 KB、不解码，
 *                  照样拿到时长、尺寸、编码、创建时间；webm 之类退回 16:9 占位
 *   ④ 播放永远不依赖 PHP：灯箱直接用原文件直链，由 nginx 处理 206 分段请求
 * 注意：宝塔默认把 shell_exec / proc_open 列入「禁用函数」，要抽帧得先在
 *       PHP 设置里放开；放开前会自动降级到 ③ —— 相册照常用，只是没有封面帧。 */

/** 能否执行外部命令（shell_exec 被禁用时 function_exists 即为 false） */
function can_exec() {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = function_exists('shell_exec') && function_exists('escapeshellarg');
    if ($ok) {
        $d = (string)ini_get('disable_functions');
        $ok = ($d === '') || !preg_match('/(^|,)\s*(shell_exec|exec|proc_open|popen|passthru|system|escapeshellarg)\s*(,|$)/i', $d);
    }
    return $ok;
}

/** 定位外部工具（ffmpeg / ffprobe）：显式配置 → 常见路径 → PATH。静态缓存，一次请求只探一遍。 */
function tool_path($name) {
    global $CONFIG;
    static $c = [];
    if (array_key_exists($name, $c)) return $c[$name];
    $c[$name] = '';
    if (!can_exec()) return '';
    $cfg = ($name === 'ffmpeg') ? (string)$CONFIG['ffmpeg'] : (string)$CONFIG['ffprobe'];
    if ($cfg !== '' && @is_file($cfg)) { $c[$name] = $cfg; return $c[$name]; }
    foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/',
              '/www/server/ffmpeg/bin/', '/usr/local/ffmpeg/bin/',
              '/www/server/' . $name . '/bin/'] as $d) {
        if (@is_file($d . $name)) { $c[$name] = $d . $name; return $c[$name]; }
    }
    if ($name === 'ffprobe') {                       // 常见情形：ffprobe 就躺在 ffmpeg 旁边
        $ff = tool_path('ffmpeg');
        if ($ff !== '') { $s = dirname($ff) . '/ffprobe'; if (@is_file($s)) { $c[$name] = $s; return $c[$name]; } }
    }
    $win = stripos(PHP_OS, 'win') === 0;
    if ($win) {                                       // Windows：where 可能返回多行，取第一行
        $out = trim((string)@shell_exec('where ' . $name . ' 2>nul'));
        if ($out !== '') {
            $line = trim(explode("\n", $out)[0]);
            if ($line !== '' && @is_file($line)) { $c[$name] = $line; return $c[$name]; }
        }
    } else {
        $p = trim((string)@shell_exec('command -v ' . $name . ' 2>/dev/null'));
        if ($p === '') $p = trim((string)@shell_exec('which ' . $name . ' 2>/dev/null'));
        if ($p !== '' && @is_file($p)) { $c[$name] = $p; return $c[$name]; }
    }
    return $c[$name];
}

/** 本机能否为视频生成封面帧 */
function poster_ok() { return tool_path('ffmpeg') !== ''; }

/** 秒 → 0:47 / 1:23:45；0 或无效返回空串 */
function fmt_dur($sec) {
    $s = (int)round((float)$sec);
    if ($s <= 0) return '';
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $ss = $s % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $ss) : sprintf('%d:%02d', $m, $ss);
}

/**
 * 在 $buf 的 [$start,$end) 内找第一个 ISO BMFF 盒子 $type。
 * 返回 [内容起点, 内容终点)，未找到或结构越界返回 null。
 */
function box_find($buf, $type, $start, $end) {
    $p = $start;
    while ($p + 8 <= $end) {
        $sz = unpack('N', substr($buf, $p, 4))[1];
        $ty = substr($buf, $p + 4, 4);
        $hs = 8;
        if ($sz === 1) {                                   // 64 位长度
            if ($p + 16 > $end) return null;
            $hi = unpack('N', substr($buf, $p + 8, 4))[1];
            $lo = unpack('N', substr($buf, $p + 12, 4))[1];
            $sz = $hi * 4294967296 + $lo;
            $hs = 16;
        } elseif ($sz === 0) { $sz = $end - $p; }          // 延伸到末尾
        if ($sz < $hs || $p + $sz > $end) return null;     // 结构坏了，停止（不猜）
        if ($ty === $type) return [$p + $hs, $p + $sz];
        $p += $sz;
    }
    return null;
}

/**
 * 纯 PHP 解析 MP4/MOV：时长、尺寸、视频/音频编码、创建时间。
 * 只按盒子头部 seek，大文件里跳过 mdat，实际读取通常只有几 KB。
 */
function mp4_probe($abs) {
    $out = [];
    $fh  = @fopen($abs, 'rb');
    if (!$fh) return $out;
    $size = (int)@filesize($abs);
    $head = @fread($fh, 12);
    if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') { fclose($fh); return $out; }

    $moov = ''; $off = 0;
    while ($off + 8 <= $size) {
        if (@fseek($fh, $off) !== 0) break;
        $hdr = @fread($fh, 16);
        if (strlen($hdr) < 8) break;
        $asz = unpack('N', substr($hdr, 0, 4))[1];
        $typ = substr($hdr, 4, 4);
        $hs  = 8;
        if ($asz === 1) {
            if (strlen($hdr) < 16) break;
            $hi = unpack('N', substr($hdr, 8, 4))[1];
            $lo = unpack('N', substr($hdr, 12, 4))[1];
            $asz = $hi * 4294967296 + $lo; $hs = 16;
        } elseif ($asz === 0) { $asz = $size - $off; }
        if ($asz < $hs) break;
        if ($typ === 'moov') {                              // 通常几 KB~几百 KB（faststart 在前，否则在尾）
            $take = min($asz - $hs, 8388608);
            @fseek($fh, $off + $hs);
            $moov = (string)@fread($fh, $take);
            break;
        }
        $off += $asz;
    }
    fclose($fh);
    if ($moov === '') return $out;
    $me = strlen($moov);

    // mvhd：时长 + 创建时间（1904-01-01 起算）
    $r = box_find($moov, 'mvhd', 0, $me);
    if ($r && $r[1] - $r[0] >= 100) {
        if (ord($moov[$r[0]]) === 1 && $r[1] - $r[0] >= 112) {
            $ct = (int)unpack('J', substr($moov, $r[0] + 4, 8))[1];
            $ts = unpack('N', substr($moov, $r[0] + 20, 4))[1];
            $du = (int)unpack('J', substr($moov, $r[0] + 24, 8))[1];
        } else {
            $ct = (int)unpack('N', substr($moov, $r[0] + 4, 4))[1];
            $ts = unpack('N', substr($moov, $r[0] + 12, 4))[1];
            $du = (int)unpack('N', substr($moov, $r[0] + 16, 4))[1];
        }
        if ($ts > 0 && $du > 0 && $du < 4294967295) $out['dur'] = $du / $ts;
        if ($ct > 2082844800) {
            $t = $ct - 2082844800;
            if ($t > 946684800 && $t < 4102444800) $out['ctime'] = $t;   // 落在 2000~2100 才算有效
        }
    }

    // 每条 trak：tkhd 取尺寸（音频 trak 宽高为 0，取面积最大者），stsd 取编码 fourcc
    $codecV = ''; $codecA = ''; $best = 0; $p = 0;
    while (($t = box_find($moov, 'trak', $p, $me))) {
        $p = $t[1];
        $tk = box_find($moov, 'tkhd', $t[0], $t[1]);
        if ($tk) {
            $wo = (ord($moov[$tk[0]]) === 1) ? 88 : 76;      // v1 比 v0 多 12 字节的 64 位时间字段
            if ($tk[1] - $tk[0] >= $wo + 8) {
                $w = unpack('N', substr($moov, $tk[0] + $wo, 4))[1] / 65536;      // 16.16 定点
                $h = unpack('N', substr($moov, $tk[0] + $wo + 4, 4))[1] / 65536;
                if ($w > 0 && $h > 0 && $w * $h > $best) {
                    $best = $w * $h; $out['w'] = (int)round($w); $out['h'] = (int)round($h);
                }
            }
        }
        $mdia = box_find($moov, 'mdia', $t[0], $t[1]);
        if (!$mdia) continue;
        $hdl  = box_find($moov, 'hdlr', $mdia[0], $mdia[1]);
        $kind = ($hdl && $hdl[1] - $hdl[0] >= 12) ? substr($moov, $hdl[0] + 8, 4) : '';
        $minf = box_find($moov, 'minf', $mdia[0], $mdia[1]);
        $stbl = $minf ? box_find($moov, 'stbl', $minf[0], $minf[1]) : null;
        $stsd = $stbl ? box_find($moov, 'stsd', $stbl[0], $stbl[1]) : null;
        if ($stsd && $stsd[1] - $stsd[0] >= 16) {
            $four = substr($moov, $stsd[0] + 12, 4);
            if ($kind === 'vide' && $codecV === '') $codecV = $four;
            if ($kind === 'soun' && $codecA === '') $codecA = $four;
        }
    }
    $cv = ['avc1'=>'H.264','avc3'=>'H.264','hvc1'=>'H.265','hev1'=>'H.265','dvh1'=>'H.265','dvhe'=>'H.265',
           'vp09'=>'VP9','av01'=>'AV1','mp4v'=>'MPEG-4','apcn'=>'ProRes','jpeg'=>'Motion JPEG'];
    $ca = ['mp4a'=>'AAC','ac-3'=>'AC-3','ec-3'=>'E-AC-3','opus'=>'Opus','Opus'=>'Opus',
           'alac'=>'ALAC','.mp3'=>'MP3','twos'=>'PCM','sowt'=>'PCM'];
    if ($codecV !== '') $out['codec']  = $cv[$codecV] ?? strtoupper(trim($codecV));
    if ($codecA !== '') $out['acodec'] = $ca[$codecA] ?? trim($codecA);
    return $out;
}

/** 视频信息：ffprobe 优先，退化到纯 PHP 解析。键固定：dur,w,h,fps,codec,acodec,bitrate,ctime,src */
function probe_video($abs) {
    $out = ['dur' => 0.0, 'w' => 0, 'h' => 0, 'fps' => 0.0, 'codec' => '',
            'acodec' => '', 'bitrate' => 0, 'ctime' => 0, 'src' => ''];

    $pb = tool_path('ffprobe');
    if ($pb !== '') {
        $cmd = escapeshellarg($pb) . ' -v quiet -print_format json -show_format -show_streams '
             . escapeshellarg($abs) . ' 2>&1';
        $j = json_decode((string)@shell_exec($cmd), true);
        if (is_array($j) && !empty($j['streams'])) {
            foreach ($j['streams'] as $s) {
                $ct = (string)($s['codec_type'] ?? '');
                if ($ct === 'video' && !$out['w']) {
                    $out['w']     = (int)($s['width'] ?? 0);
                    $out['h']     = (int)($s['height'] ?? 0);
                    $out['codec'] = strtoupper((string)($s['codec_name'] ?? ''));
                    $fr = (string)($s['r_frame_rate'] ?? '');            // 形如 "30000/1001"
                    if (strpos($fr, '/') !== false) {
                        $q = explode('/', $fr);
                        if ((float)$q[1] > 0) $out['fps'] = round((float)$q[0] / (float)$q[1], 2);
                    }
                } elseif ($ct === 'audio' && $out['acodec'] === '') {
                    $out['acodec'] = strtoupper((string)($s['codec_name'] ?? ''));
                }
            }
            $f = isset($j['format']) && is_array($j['format']) ? $j['format'] : [];
            if (!empty($f['duration']))   $out['dur']     = (float)$f['duration'];
            if (!empty($f['bit_rate']))   $out['bitrate'] = (int)$f['bit_rate'];
            if (!empty($f['tags']['creation_time'])) {
                $t = @strtotime((string)$f['tags']['creation_time']);
                if ($t) $out['ctime'] = $t;
            }
            $out['src'] = 'ffprobe';
        }
    }

    if ($out['src'] === '') {
        $ext = ext_of($abs);
        if (in_array($ext, ['mp4', 'mov', 'm4v'], true)) {
            $m = mp4_probe($abs);
            if ($m) {
                foreach (['dur', 'w', 'h', 'ctime'] as $k) { if (!empty($m[$k])) $out[$k] = $m[$k]; }
                if (!empty($m['codec']))  $out['codec']  = $m['codec'];
                if (!empty($m['acodec'])) $out['acodec'] = $m['acodec'];
                $out['src'] = 'php';
            }
        }
    }
    if (empty($out['bitrate']) && $out['dur'] > 0) {          // 没有码率就用体积/时长反推
        $sz = (int)@filesize($abs);
        if ($sz > 0) $out['bitrate'] = (int)round($sz * 8 / $out['dur']);
    }
    return $out;
}

/** 视频信息的磁盘缓存（原子写入）。key 含 mtime + 探测能力：装了 ffprobe 会自动重探。 */
function probe_cached($abs) {
    global $CONFIG;
    $dir = $CONFIG['cache_dir'] . '/probe';
    $sig = (tool_path('ffprobe') !== '') ? 'ff' : 'php';
    $f   = $dir . '/' . md5(rel_of($abs) . '|' . @filemtime($abs) . '|' . $sig) . '.json';
    if (is_file($f)) {
        $j = @json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && $j) return $j;
    }
    $p = probe_video($abs);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $f . '.' . getmypid() . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, json_encode($p, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $f);
    return $p;
}

/** 视频的"参数行"：复用 EXIF 的键值结构，灯箱与时间轴共用同一套渲染 */
function read_video_meta($abs) {
    $p = probe_cached($abs);
    $r = [];
    if (!empty($p['dur']))     $r['时长']     = fmt_dur($p['dur']);
    if (!empty($p['w']))       $r['分辨率']   = (int)$p['w'] . ' × ' . (int)$p['h'];
    if (!empty($p['fps']))     $r['帧率']     = rtrim(rtrim(number_format((float)$p['fps'], 2, '.', ''), '0'), '.') . ' fps';
    if (!empty($p['codec']))   $r['视频编码'] = $p['codec'];
    if (!empty($p['acodec']))  $r['音频']     = $p['acodec'];
    if (!empty($p['bitrate'])) $r['码率']     = number_format((float)$p['bitrate'] / 1048576, 2) . ' Mbps';
    if (!empty($p['ctime']))   $r['创建时间'] = date('Y-m-d H:i:s', (int)$p['ctime']);
    $r['容器'] = strtoupper(ext_of($abs));
    return $r;
}

/**
 * 用 ffmpeg 抽一帧到临时 JPEG。默认取第 1 秒（片头常有黑场淡入），不足 1 秒退回 0 秒。
 * $seek 指定时只试该秒数 —— 动图封面与实况照片的静帧都要"第 0 帧"（首帧＝静止画面）。
 * 无 ffmpeg / 抽帧失败返回 false。调用方负责 unlink。
 */
function video_frame($abs, $seek = null) {
    global $CONFIG;
    $bin = tool_path('ffmpeg');
    if ($bin === '') return false;
    if (!is_dir($CONFIG['cache_dir'])) @mkdir($CONFIG['cache_dir'], 0755, true);
    $tmp = $CONFIG['cache_dir'] . '/.frame_' . getmypid() . mt_rand(1000, 9999) . '.jpg';
    @set_time_limit(120);
    foreach (($seek === null ? [1, 0] : [(float)$seek]) as $ss) {
        $cmd = escapeshellarg($bin) . ' -nostdin -y -loglevel error'
             . ' -ss ' . $ss . ' -i ' . escapeshellarg($abs)
             . ' -frames:v 1 -an -q:v 3 ' . escapeshellarg($tmp) . ' 2>&1';
        @shell_exec($cmd);
        if (is_file($tmp) && @filesize($tmp) > 0) return $tmp;
    }
    @unlink($tmp);
    return false;
}

/* ───── 动图探测（GIF / APNG / 动态 WebP）─────
 * GD 只解第一帧，所以"是不是动图"必须自己判。这里纯 PHP 遍历容器块结构，
 * 只读文件开头几 MB、不解码像素，2H2G 上几乎零成本；结论落盘缓存，重复请求不再解析。 */

/**
 * 判定动画性。返回 ['a'=>是否动图, 'f'=>帧数, 'd'=>总时长(毫秒), 'w'=>宽, 'h'=>高, 't'=>是否含透明]
 */
function anim_probe($abs) {
    $out = ['a' => false, 'f' => 0, 'd' => 0, 'w' => 0, 'h' => 0, 't' => 0];
    $ext = ext_of($abs);
    if (!in_array($ext, ['gif', 'png', 'webp'], true)) return $out;
    $fh = @fopen($abs, 'rb');
    if (!$fh) return $out;
    $b = (string)@fread($fh, 8388608);              // 最多读 8MB：帧结构一定出现在前部
    fclose($fh);
    $n = strlen($b);
    if ($n < 16) return $out;

    if ($ext === 'gif') {
        if (substr($b, 0, 3) !== 'GIF') return $out;
        $out['w'] = unpack('v', substr($b, 6, 2))[1];
        $out['h'] = unpack('v', substr($b, 8, 2))[1];
        $fl = ord($b[10]);
        $p  = 13 + (($fl & 0x80) ? 3 * (1 << (($fl & 7) + 1)) : 0);   // 跳过全局色表
        $frames = 0; $delay = 0;
        while ($p < $n) {
            $c = ord($b[$p]);
            if ($c === 0x3B) break;                                   // trailer
            if ($c === 0x21) {                                        // 扩展块
                if ($p + 1 >= $n) break;
                if (ord($b[$p + 1]) === 0xF9 && $p + 6 <= $n) {        // 图形控制扩展
                    if (ord($b[$p + 3]) & 0x01) $out['t'] = 1;         // 透明色标志
                    $delay += unpack('v', substr($b, $p + 4, 2))[1];   // 延迟，单位 1/100 秒
                }
                $p += 2;
                while ($p < $n && ord($b[$p]) !== 0) { $p += ord($b[$p]) + 1; }
                $p++;
            } elseif ($c === 0x2C) {                                  // 图像描述符 → 一帧
                $frames++;
                if ($p + 10 > $n) break;
                $lf = ord($b[$p + 9]);
                $p += 10 + (($lf & 0x80) ? 3 * (1 << (($lf & 7) + 1)) : 0);
                $p++;                                                 // LZW 最小码长
                while ($p < $n && ord($b[$p]) !== 0) { $p += ord($b[$p]) + 1; }
                $p++;
            } else { $p++; }
        }
        $out['f'] = $frames;
        $out['d'] = $delay * 10;                                      // 1/100 秒 → 毫秒
        $out['a'] = ($frames > 1);
        return $out;
    }

    if ($ext === 'png') {                                             // APNG：acTL 块
        if (substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") return $out;
        $p = 8; $actl = false; $fk = 0; $delay = 0;
        while ($p + 12 <= $n) {
            $len = unpack('N', substr($b, $p, 4))[1];
            $ty  = substr($b, $p + 4, 4);
            if ($ty === 'IHDR' && $len >= 13 && $p + 21 <= $n) {
                $out['w'] = unpack('N', substr($b, $p + 8, 4))[1];
                $out['h'] = unpack('N', substr($b, $p + 12, 4))[1];
                $ct = ord($b[$p + 17]);                               // 4=灰+透明，6=真彩+透明
                if ($ct === 4 || $ct === 6) $out['t'] = 1;
            } elseif ($ty === 'acTL' && $len >= 8) {
                $actl   = true;
                $fk     = unpack('N', substr($b, $p + 8, 4))[1];
            } elseif ($ty === 'tRNS') {
                $out['t'] = 1;
            } elseif ($ty === 'fcTL' && $len >= 26 && $p + 34 <= $n) {
                $dn = unpack('n', substr($b, $p + 28, 2))[1];
                $dd = unpack('n', substr($b, $p + 30, 2))[1];
                if ($dd > 0) $delay += (int)round($dn * 1000 / $dd);
            }
            // 不提前退出：fcTL 分散在整个文件里，要全部累加才拿得到总时长
            $p += 12 + $len;
        }
        $out['f'] = $fk;
        $out['d'] = $delay;
        $out['a'] = ($actl && $fk > 1);
        return $out;
    }

    // WebP：RIFF 块遍历，ANIM 块 + ANMF 帧
    if (substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') return $out;
    $p = 12; $anim = false; $frames = 0; $delay = 0;
    while ($p + 8 <= $n) {
        $four = substr($b, $p, 4);
        $len  = unpack('V', substr($b, $p + 4, 4))[1];
        if ($p + 8 + $len > $n) break;
        if ($four === 'VP8X' && $len >= 10) {
            // VP8X 载荷 = 标志(1) + 保留(3) + 画布宽-1(3) + 画布高-1(3)
            // —— 保留的 3 字节在最前面，宽高从 +12 / +15 起（实测 ffmpeg 产物校准）
            if (ord($b[$p + 8]) & 0x10) $out['t'] = 1;                  // Alpha 通道标志
            $out['w'] = unpack('V', substr($b, $p + 12, 3) . "\0")[1] + 1;   // 24 位、存的是"减一"
            $out['h'] = unpack('V', substr($b, $p + 15, 3) . "\0")[1] + 1;
        } elseif ($four === 'ANIM') {
            $anim = true;
        } elseif ($four === 'ANMF' && $len >= 16) {
            $frames++;
            $delay += unpack('V', substr($b, $p + 20, 3) . "\0")[1];   // 帧时长，毫秒
        }
        $p += 8 + $len + ($len & 1);                                    // RIFF 块按偶数字节对齐
    }
    $out['f'] = $frames;
    $out['d'] = $delay;
    $out['a'] = ($anim && $frames > 1);
    return $out;
}

/** 动图探测的磁盘缓存（原子写入），与 probe_cached 同构 */
function anim_cached($abs) {
    global $CONFIG;
    static $mem = [];
    $dflt = ['a' => false, 'f' => 0, 'd' => 0, 'w' => 0, 'h' => 0, 't' => 0, 'ta' => 0];
    if (!is_anim_ext($abs)) return $dflt;
    $rel = rel_of($abs);
    if (isset($mem[$rel])) return $mem[$rel];
    $dir = $CONFIG['cache_dir'] . '/anim';
    // 键里带版本号：探测逻辑升级（新增"实测真实 alpha"）后旧缓存自动失效
    $f   = $dir . '/' . md5($rel . '|' . @filemtime($abs) . '|v2') . '.json';
    if (is_file($f)) {
        $j = @json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && $j) { $mem[$rel] = $j + $dflt; return $mem[$rel]; }
    }
    $p = anim_probe($abs);
    // 容器标志只是"可能有"，必须实测：GIF/WebP 的编码器会拿逐帧透明标志当
    // 帧间差分压缩用（实测 testsrc 转的 GIF 30 帧里 29 帧带标志，却没有一个透明像素），
    // 只看标志会把普通动图误判成表情包，白白丢掉 1/10 体积的 MP4。
    if (!empty($p['t'])) $p['ta'] = anim_alpha($abs);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $f . '.' . getmypid() . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, json_encode($p, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $f);
    $mem[$rel] = $p;
    return $p;
}

/**
 * 实测"到底有没有透明像素"。返回 1（有）/ 0（全不透明）。
 *
 * 为什么不能只信容器标志：GIF 的图形控制扩展和 WebP 的 VP8X 都会声明透明通道，
 * 但编码器普遍把它当帧间差分压缩用 —— 上一帧没变的像素标成"透明"就能省掉色板索引。
 * 实测 30 帧的 testsrc GIF 有 29 帧带透明标志，逐像素解码却一个 alpha=0 都没有。
 *
 * 做法：把 alpha 通道单独抽成一个灰度平面，取每帧的最小值（signalstats YMIN）。
 * 255 = 该帧全不透明；只要有一帧小于阈值，就是真的用了透明。整片一次解码即可，
 * 480×320×30 帧实测 0.2 秒，且结论跟着 anim_cached 落盘，同一个文件一辈子只算一次。
 *
 * 探不出来时返回 1（保守当透明 → 不转码 → 退回原图播放），宁可少省体积也不压出黑块。
 */
function anim_alpha($abs) {
    $bin = tool_path('ffmpeg');
    if ($bin === '') return 1;                    // 没 ffmpeg 本来就转不了码，无需纠结
    @set_time_limit(90);
    $vf  = 'format=rgba,alphaextract,signalstats,'
         . 'metadata=print:key=lavfi.signalstats.YMIN:file=-';
    $cmd = escapeshellarg($bin) . ' -nostdin -loglevel error -i ' . escapeshellarg($abs)
         . ' -vf ' . escapeshellarg($vf) . ' -an -f null - 2>&1';
    $out = (string)@shell_exec($cmd);
    if (preg_match_all('/YMIN=(\d+)/', $out, $m)) {
        foreach ($m[1] as $v) { if ((int)$v < 250) return 1; }
        return 0;
    }
    return 1;
}

/** 是不是动图（供卡片角标、灯箱、参数行使用） */
function is_anim($abs) {
    return !empty(anim_cached($abs)['a']);
}

/** 毫秒 → 「2.4 秒」/「1 分 05 秒」 */
function fmt_ms($ms) {
    $s = (int)round((float)$ms / 1000);
    if ($s <= 0) return '';
    if ($s < 60) return rtrim(rtrim(number_format($ms / 1000, 1, '.', ''), '0'), '.') . ' 秒';
    return intdiv($s, 60) . ' 分 ' . sprintf('%02d', $s % 60) . ' 秒';
}

/** 这个文件是不是容器合法的 MP4（ffmpeg 转码可能装上没有 libx264 的构建） */
function mp4_ok($path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $h = (string)@fread($fh, 12);
    fclose($fh);
    return strlen($h) >= 12 && substr($h, 4, 4) === 'ftyp';
}

/**
 * 这张动图是否适合转 MP4。三个条件：
 *   有 ffmpeg、不超过 anim_max_sec（低配服务器保护）、实测不含透明像素
 *   —— 真有透明通道的动图（表情包/贴纸）不转码：MP4 的 yuv420p 没有 alpha，
 *      透明区必然被压成一块死色。这种直接播原图，透明才是它存在的意义。
 *   注意判据是 ta（实测）而不是 t（容器标志）—— 见 anim_alpha() 的说明。
 */
function anim_can_mp4($abs) {
    global $CONFIG;
    if (!is_anim($abs) || tool_path('ffmpeg') === '') return false;
    $a = anim_cached($abs);
    if (!empty($a['ta'])) return false;
    if (!empty($a['d']) && $a['d'] / 1000 > (float)$CONFIG['anim_max_sec']) return false;
    return true;
}

/**
 * 动图 → MP4 缓存（原子写入）。失败返回 ''，调用方回落"直接播原图"。
 * 复用灯箱已有的 <video> 与 HTTP Range 分段，体积通常只有原 GIF 的 1/10 上下。
 * 装不上 libx264 的 ffmpeg 会失败 —— 写一个 .bad 标记，避免每次请求都白烧一次 CPU。
 */
function anim_mp4($abs, $rel) {
    global $CONFIG;
    if (!anim_can_mp4($abs)) return '';
    $dir = $CONFIG['cache_dir'] . '/anim';
    $f   = $dir . '/' . md5($rel . '|' . @filemtime($abs)) . '.mp4';
    if (is_file($f) && @filesize($f) > 0) return $f;
    $bad = $f . '.bad';
    if (is_file($bad) && @filemtime($bad) >= @filemtime($abs)) return '';

    $bin = tool_path('ffmpeg');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ml = ini_get('memory_limit');
    if ($ml !== '-1' && (int)$ml < 256) @ini_set('memory_limit', '256M');
    @set_time_limit(180);

    // 压白底滤镜：把源先拆成两路，一路被 drawbox 整幅填白当作背景，再把带 alpha 的
    // 原图 overlay 上去。用 split 而不是 color 滤镜造背景，是为了让背景的帧数/PTS
    // 与源完全一致 —— color 的默认 25fps 会把 10fps 的 GIF 尾巴截短（实测 3.00s→2.92s）。
    // 源本身不透明时这条链是恒等变换，纯属兜底，防止容器标志与像素不完全一致时压出黑块。
    $fc = '[0:v]scale=trunc(iw/2)*2:trunc(ih/2)*2,format=rgba,split=2[fg][tmp];'
        . '[tmp]drawbox=c=white@1:t=fill,format=rgb24[bg];'
        . '[bg][fg]overlay,format=yuv420p[v]';

    $tmp = $f . '.' . getmypid() . mt_rand(1000, 9999) . '.tmp';
    $cmd = escapeshellarg($bin) . ' -nostdin -y -loglevel error -i ' . escapeshellarg($abs)
         . ' -an -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p'
         . ' -filter_complex ' . escapeshellarg($fc) . ' -map ' . escapeshellarg('[v]')
         . ' -movflags +faststart -f mp4 ' . escapeshellarg($tmp) . ' 2>&1';
    @shell_exec($cmd);
    if (is_file($tmp) && @filesize($tmp) > 0 && mp4_ok($tmp)) {
        if (@rename($tmp, $f)) { @unlink($bad); return $f; }
    }
    @unlink($tmp);
    @file_put_contents($bad, '1');
    return '';
}

/* ───── 缩略图生成（Web 与 CLI 预热共用） ───── */

/** 浏览器/客户端是否支持 WebP */
function prefer_webp() {
    return function_exists('imagewebp')
        && isset($_SERVER['HTTP_ACCEPT'])
        && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
}

/**
 * 把一张"源图"（原图，或从视频抽出的帧）缩放编码进缓存。返回缓存路径；失败 false。
 * 可靠性核心：临时文件 + rename 原子写入 —— 读方永远不会看到半张坏图。
 */
function thumb_write($srcPath, $cached, $w, $fmt = 'jpg') {
    global $CONFIG;
    $ext = ext_of($srcPath);

    if (!is_dir($CONFIG['cache_dir'])) @mkdir($CONFIG['cache_dir'], 0755, true);

    // 解码内存 ≈ 宽×高×5 字节：12000×9000 的全景图约需 460MB
    $ml = ini_get('memory_limit');
    if ($ml !== '-1' && (int)$ml < 512) @ini_set('memory_limit', '512M');
    @set_time_limit(120);

    $src = gd_load($srcPath, $ext);
    if (!$src) return false;
    exif_fix($src, $srcPath);
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) { imagedestroy($src); return false; }

    $tw = min($w, $sw);
    $th = max(1, (int)round($sh * $tw / $sw));
    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // 透明区压白底
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
    imagedestroy($src);

    $tmp = $cached . '.' . getmypid() . mt_rand(1000, 9999) . '.tmp';
    $ok  = ($fmt === 'webp') ? @imagewebp($dst, $tmp, $CONFIG['quality'])
                             : @imagejpeg($dst, $tmp, $CONFIG['quality']);
    imagedestroy($dst);
    if ($ok && @rename($tmp, $cached)) return $cached;
    @unlink($tmp);
    return false;
}

/**
 * 生成（或读取）指定宽度的缩略图缓存。返回缓存路径；失败返回 false。
 * 图片走 GD；视频先由 ffmpeg 抽一帧，再走同一条 GD + 原子写入的链路。
 */
function generate_thumb($abs, $rel, $w, $fmt = 'jpg') {
    global $CONFIG;
    $mtime  = @filemtime($abs);
    $cached = $CONFIG['cache_dir'] . '/' . md5($rel . '|' . $w . '|' . $mtime) . '.' . $fmt;
    if (is_file($cached) && @filesize($cached) > 0) return $cached;

    if (is_video($abs)) {
        $frame = video_frame($abs);
        if (!$frame) return false;                  // 无 ffmpeg / 抽帧失败 → 由前端回落占位封面
        $ok = thumb_write($frame, $cached, $w, $fmt);
        @unlink($frame);
        return $ok ? $cached : false;
    }
    if (thumb_write($abs, $cached, $w, $fmt)) return $cached;

    // GD 解不了的图，改由 ffmpeg 取首帧后再走同一条 GD 缩放 + 原子写入：
    //   · 动态 WebP —— GD 只认静态 WebP，整帧解码直接失败（历史上会整张回落原图）
    //   · HEIC / HEIF —— GD 没有 HEIC 解码器；实况照片借配对 MOV 抽首帧，画面与静帧一致
    //   · AVIF —— GD 编译时没带 avif 解码（< 8.1 或裁剪版 GD）时同样走抽帧
    if (!needs_ffmpeg_frame($abs) || tool_path('ffmpeg') === '') return false;
    foreach ([$abs, live_pair($abs)] as $src) {
        if (!is_string($src) || $src === '' || !is_file($src)) continue;
        $frame = video_frame($src, 0);              // 首帧 = 动图的天然封面、实况的静止画面
        if (!$frame) continue;
        $ok = thumb_write($frame, $cached, $w, $fmt);
        @unlink($frame);
        if ($ok) return $cached;
    }
    return false;
}

/** 收集全站作品（供预热使用）。已配对成实况照片的 MOV 不再单独出现 */
function all_items() {
    global $CONFIG;
    $albums = scan_albums($CONFIG['allowed_ext']);
    $live   = live_pairs($albums);
    $out    = [];
    foreach ($albums as $files) {
        foreach ($files as $p) {
            if (isset($live['drop'][$p])) continue;      // 实况照片的动态部分，由静帧承载
            $out[] = $p;
        }
    }
    return $out;
}

/** 页面触发后台预热：页面先送达，再用当前请求剩余时间生成缓存 */
function background_warm($budget_sec = 12) {
    global $CONFIG;
    if (!is_dir($CONFIG['cache_dir'])) @mkdir($CONFIG['cache_dir'], 0755, true);
    $lock = @fopen($CONFIG['cache_dir'] . '/warm.lock', 'w');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return; }

    $t0      = time();
    $fmt     = prefer_webp() ? 'webp' : 'jpg';
    $canPost = poster_ok();
    foreach (all_items() as $p) {
        if (is_video($p)) {
            probe_cached($p);                                 // 时长/尺寸缓存：时间轴与卡片都要用
            if (!$canPost) continue;                          // 没 ffmpeg：不生成封面，快速跳过
        } else {
            anim_cached($p);                                  // 动图判定：卡片角标要用
            $lp = live_pair($p);
            if ($lp !== '') probe_cached($lp);                // 实况的动态部分不在 all_items 里，单独预热
        }
        generate_thumb($p, rel_of($p), $CONFIG['thumb_w'], $fmt);
        if (time() - $t0 >= $budget_sec) { flock($lock, LOCK_UN); fclose($lock); return; }
    }
    @file_put_contents($CONFIG['cache_dir'] . '/warm_done', '1');
    flock($lock, LOCK_UN); fclose($lock);
}

/* ───── CLI 预热：php index.php --warm ─────
 * 宝塔计划任务：/www/server/php/83/bin/php /www/wwwroot/你的目录/index.php --warm
 * 把缩略图生成完全挪出用户请求，首屏直接全速。重复跑为增量（key 含 mtime）。 */
if (php_sapi_name() === 'cli' && isset($argv) && in_array('--warm', $argv, true)) {
    $fmt = function_exists('imagewebp') ? 'webp' : 'jpg';   // CLI 无协商头，按 GD 能力选
    $canPost = poster_ok();
    $pbin = tool_path('ffprobe');
    printf("鎏光MrdT相册 · 预热缩略图缓存 [%s]\n", $fmt);
    if ($canPost) printf("  抽帧：ffmpeg → %s\n", tool_path('ffmpeg'));
    else          echo "  未检测到 ffmpeg（或 shell_exec 被禁用）：视频将没有封面帧，但仍可正常播放\n";
    echo $pbin !== '' ? "  视频信息：ffprobe\n"
                      : "  视频信息：纯 PHP 解析（mp4/mov 时长·尺寸·编码）\n";
    echo "  动图：纯 PHP 解析容器块结构（GIF / APNG / 动态 WebP），"
       . ($canPost ? "实测透明通道后转 MP4 播放\n" : "无 ffmpeg → 灯箱直接播原动图\n");
    echo "  实况照片：同名 HEIC/JPG + MOV 自动配对"
       . ($canPost ? "，用 MOV 首帧作封面\n" : "（无 ffmpeg 拿不到静帧封面，仍可按住播放）\n");

    $n = 0; $fail = 0; $vok = 0; $vskip = 0;
    $an = 0; $anMp4 = 0; $anKeep = 0; $live = 0; $livePoster = 0;
    $anTrans = 0; $anLong = 0; $anNoFf = 0; $t0 = time();
    foreach (all_items() as $p) {
        $rel = rel_of($p);
        meta_cached($p);                       // 顺手把拍摄参数也缓存好（时间轴首屏零等待）
        if (is_video($p)) {
            $pv = probe_cached($p);
            if (!empty($pv['dur']) || !empty($pv['w'])) $vok++;
            if (!$canPost) { $vskip++; continue; }
        } else {
            $a  = anim_cached($p);
            $lp = live_pair($p);
            if ($lp !== '') {
                $live++;
                probe_cached($lp);
                if (!$canPost) $livePoster++;
            }
            if (!empty($a['a'])) {
                $an++;
                if (anim_can_mp4($p)) {                        // 动图预转 MP4：灯箱首开零等待
                    if (anim_mp4($p, $rel) !== '') $anMp4++; else $anKeep++;
                } else {                                       // 保持原图画质，分三种原因
                    $anKeep++;
                    if (!empty($a['ta']))      $anTrans++;     // 实测有透明像素 → 转 MP4 会把透明压成死色
                    elseif (!$canPost)         $anNoFf++;
                    else                       $anLong++;      // 超过 anim_max_sec
                }
            }
        }
        foreach ([$CONFIG['thumb_w'], $CONFIG['view_w']] as $w) {
            if (generate_thumb($p, $rel, $w, $fmt)) { $n++; }
            else { $fail++; echo "  失败: {$rel}\n"; }
        }
    }
    save_manifest(manifest());
    printf("完成：生成 %d 个缓存（%s），失败 %d 个，耗时 %d 秒。\n", $n, $fmt, $fail, time() - $t0);
    if ($vok || $vskip) printf("视频：%d 个已解析到时长/尺寸，%d 个跳过封面（无 ffmpeg）。\n", $vok, $vskip);
    if ($an)  printf("动图：%d 个，%d 个已转 MP4（%d 个保持原图：%d 有透明 / %d 超长 / %d 无 ffmpeg）。\n",
                     $an, $anMp4, $anKeep, $anTrans, $anLong, $anNoFf);
    if ($live) printf("实况照片：%d 条已配对（%d 条因无 ffmpeg 拿不到静帧封面，仍可按住播放）。\n", $live, $livePoster);
    exit;
}

/** 请求里的相对路径 → 安全的绝对路径；越界/不存在/非媒体 一律返回 false。
 *  允许两类位置：站点目录内；或 extra_roots 声明的外部媒体根内（桌面 App 的符号链接图库
 *  会被 realpath 解析到站点目录之外，必须靠 extra_roots 显式放行）。 */
function safe_abs($rel) {
    global $CONFIG;
    $abs = realpath(__DIR__ . '/' . $rel);
    if (!$abs || !is_file($abs) || !is_media($abs)) return false;
    $base = realpath(__DIR__);
    if ($base && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0) return $abs;
    if ($base && $abs === $base) return false;                       // 根目录本身不是文件
    foreach ((array)$CONFIG['extra_roots'] as $root) {
        $r = realpath($root);
        if ($r && strpos($abs, $r . DIRECTORY_SEPARATOR) === 0) return $abs;
    }
    return false;
}

/** 单张媒体的元数据（文件信息 + 拍摄参数 / 视频信息），供 ?x= 接口使用 */
function meta_payload($abs) {
    $rel   = rel_of($abs);
    $parts = explode('/', $rel);
    $mtime = @filemtime($abs);
    [$iw, $ih] = img_dims($abs);
    $isV   = is_video($abs);
    $pv    = $isV ? probe_cached($abs) : null;
    $a     = $isV ? null : anim_cached($abs);
    $lp    = $isV ? '' : live_pair($abs);
    $lv    = ($lp !== '') ? probe_cached($lp) : null;
    return [
        'ok'     => true,
        'name'   => basename($abs),
        'album'  => (count($parts) > 1) ? $parts[0] : '未分类',
        'path'   => $rel,
        'kind'   => $isV ? 'video' : 'image',
        'dur'    => $isV ? (float)($pv['dur'] ?? 0) : 0,
        'anim'       => !empty($a['a']),                                   // 动图
        'animFrames' => (int)($a['f'] ?? 0),
        'animDur'    => (!empty($a['d']) ? fmt_ms($a['d']) : ''),
        'animMP4'    => (!$isV && anim_can_mp4($abs)),                     // 走转码播放而不是原图
        'live'       => ($lp !== ''),                                      // 实况照片
        'liveDur'    => (!empty($lv['dur']) ? fmt_ms($lv['dur'] * 1000) : ''),
        'liveUrl'    => ($lp !== '') ? orig_url(rel_of($lp)) : '',         // 实况的动态片段直链
        'w'      => $iw,
        'h'      => $ih,
        'size'   => human_size(@filesize($abs)),
        'mtime'  => $mtime ? date('Y-m-d H:i', $mtime) : '',
        'url'    => orig_url($rel),
        'exif'   => meta_cached($abs),
    ];
}

/* ───── 接口 1：缩略图输出 ?t=base64url路径 &w=宽度 ─────
 *   图片：GD 缩放；视频：ffmpeg 抽帧后再缩放。两者共用同一份缓存与原子写入。
 *   视频抽不出帧（无 ffmpeg）→ 404，前端据此回落占位封面；绝不把整个 mp4 当图片吐出去。 */
if (isset($_GET['t'])) {
    $rel = b64url_dec((string)$_GET['t']);
    $w   = isset($_GET['w']) ? (int)$_GET['w'] : 640;
    $w   = max(64, min(4000, $w));                 // 宽度钳制：防恶意参数放大请求打爆内存

    $abs = safe_abs($rel);
    if (!$abs) {
        http_response_code(404); exit('not found');
    }

    $ext   = ext_of($abs);
    $isVid = is_video($abs);
    header('Cache-Control: public, max-age=31536000, immutable');

    // 格式协商：优先 WebP；未命中则复用另一种格式的现成缓存（如 CLI 预热产物）
    $fmt    = prefer_webp() ? 'webp' : 'jpg';
    $cached = generate_thumb($abs, $rel, $w, $fmt);
    if (!$cached && !$isVid) {
        $alt     = ($fmt === 'webp') ? 'jpg' : 'webp';
        $altPath = $CONFIG['cache_dir'] . '/' . md5($rel . '|' . $w . '|' . @filemtime($abs)) . '.' . $alt;
        $cached  = (is_file($altPath) && @filesize($altPath) > 0) ? $altPath
                                                                 : generate_thumb($abs, $rel, $w, $alt);
    }

    if ($cached) {
        $isWebp = (ext_of($cached) === 'webp');
        header('Content-Type: ' . ($isWebp ? 'image/webp' : 'image/jpeg'));
        header('Content-Length: ' . filesize($cached));
        readfile($cached);
    } elseif ($isVid) {
        http_response_code(404); exit('no poster');        // 前端改为占位封面
    } else {   // GD 与 ffmpeg 都拿不出缩略图 → 原图直出
        $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','jfif'=>'image/jpeg',
                 'png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
                 'avif'=>'image/avif','bmp'=>'image/bmp',
                 'heic'=>'image/heic','heif'=>'image/heif'];
        header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
    }
    exit;
}

/* ───── 接口：动图播放源 ?a=base64url路径 ─────
 *   动图转成的 MP4 缓存（体积约原 GIF 的 1/10）。带 Range 支持：
 *   Safari 对 <video> 会发 Range 请求，回 200 全量有时直接不播；
 *   转码不可用（无 ffmpeg / 透明动图 / 超长）一律 404，前端据此改播原图。 */
if (isset($_GET['a'])) {
    $rel = b64url_dec((string)$_GET['a']);
    $abs = safe_abs($rel);
    if (!$abs || is_video($abs)) {
        http_response_code(404); exit('not found');
    }

    $f = anim_mp4($abs, $rel);
    if ($f === '' || !is_file($f)) { http_response_code(404); exit('no anim'); }

    $size  = (int)@filesize($f);
    $start = 0; $end = $size - 1; $partial = false;
    $rng = isset($_SERVER['HTTP_RANGE']) ? (string)$_SERVER['HTTP_RANGE'] : '';
    if ($rng !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rng, $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $partial = true;
    }

    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . ($end - $start + 1));
    if ($partial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $fh = @fopen($f, 'rb');
    if (!$fh) exit;
    @fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = @fread($fh, min(262144, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $left -= strlen($chunk);
        if (connection_aborted()) break;
    }
    fclose($fh);
    exit;
}

/* ───── 接口 2：文件元数据 + EXIF
 *   单张：?x=<base64url 路径>            → {ok,name,album,...,exif:{...}}
 *   批量：?x=<key1>,<key2>,...           → {ok:true, items:{key:元数据}}
 *         时间轴视图一屏十几张，一次请求全部取回，避免 N 个往返。
 * ───── */
if (isset($_GET['x'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=600');

    $raw = (string)$_GET['x'];

    if (strpos($raw, ',') !== false) {                   // 批量
        $out = [];
        foreach (array_slice(explode(',', $raw), 0, 60) as $k) {
            if ($k === '') continue;
            $abs = safe_abs(b64url_dec($k));
            $out[$k] = $abs ? meta_payload($abs) : ['ok' => false, 'msg' => 'not found'];
        }
        echo json_encode(['ok' => true, 'items' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $abs = safe_abs(b64url_dec($raw));                   // 单张
    if (!$abs) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => 'not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(meta_payload($abs), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ───── 页面数据 ───── */
$albums = scan_albums($CONFIG['allowed_ext']);
$live   = live_pairs($albums);    // 实况照片配对表：配对上的 MOV 不再单独成卡

$total      = 0;      // 媒体总数（已并入实况照片的 MOV 不计）
$totalBytes = 0;      // 总体积
$nVid       = 0;      // 视频数
$nAnim      = 0;      // 动图数
$nLive      = 0;      // 实况照片数
$albumStat = [];      // 每个相册的 [可见条数, 其中"非纯静态"条数]（用于数量与「张 / 项」措辞）
foreach ($albums as $an => $files) {
    $v = 0; $c = 0;
    foreach ($files as $p) {
        if (isset($live['drop'][$p])) continue;          // 实况的动态部分：由配对的那张照片承载
        $c++;
        $total++;
        $totalBytes += (int)@filesize($p);
        if (is_video($p))    { $nVid++;  $v++; }
        elseif (is_anim($p)) { $nAnim++; $v++; }
        if (isset($live['pair'][$p])) $nLive++;
    }
    $albumStat[$an] = ['n' => $c, 'mix' => $v];
}
$canPoster = poster_ok();          // 本机能否抽帧做封面

// 顶栏统计口径：视频 / 实况 / 动图任何一种存在，就说明不只是"张"照片了
$mix = [];
if ($nVid)  $mix[] = $nVid . ' 视频';
if ($nLive) $mix[] = $nLive . ' 实况';
if ($nAnim) $mix[] = $nAnim . ' 动图';
$nMix = $nVid + $nLive + $nAnim;

// 组装前端数据（顺序与 scan_albums 严格一致，前端不得再排序）
// $byAlbum 与 $items 是同一批数组：前者供服务端分区渲染，后者整体交给前端 JS。
// 用 $byAlbum 而不是"按 $files 下标去 $items 里找"，是因为实况照片的 MOV 被合并掉了，
// 两者下标不再一一对应。
$items   = [];
$byAlbum = [];
$idx     = 0;
foreach ($albums as $name => $files) {
    foreach ($files as $path) {
        if (isset($live['drop'][$path])) continue;          // 已并入配对的实况照片，不再单独成卡
        $rel   = rel_of($path);
        [$iw, $ih] = img_dims($path);
        if (!$iw || !$ih) { $iw = 4; $ih = 3; }
        $vid   = is_video($path);
        $dur   = $vid ? (float)(probe_cached($path)['dur'] ?? 0) : 0;
        $a     = $vid ? null : anim_cached($path);
        $anim  = !empty($a['a']);
        $pair  = $vid ? '' : live_pair($path);
        $lpv   = ($pair !== '') ? probe_cached($pair) : null;
        $key   = b64url_enc($rel);
        $it = [
            'i'   => $idx,                                                 // 灯箱索引（= ITEMS 下标）
            't'   => 'index.php?t=' . $key . '&w=' . $CONFIG['thumb_w'],   // 卡片缩略图 / 视频封面帧
            'v'   => 'index.php?t=' . $key . '&w=' . $CONFIG['view_w'],    // 灯箱大图 / 大封面帧
            'o'   => orig_url($rel),                                       // 原图/原片直链
            'n'   => basename($rel),                                       // 文件名
            'a'   => $name,                                                // 所属相册
            'k'   => $vid ? 'video' : 'image',                             // 媒体类型
            'np'  => ($vid && !$canPoster) ? 1 : 0,                        // 1 = 本机抽不了帧，走占位封面
            'd'   => $dur,                                                 // 时长（秒，视频才有）
            'dt'  => $vid ? fmt_dur($dur) : '',                            // 预格式化时长，卡片角标直接用
            'an'  => $anim ? 1 : 0,                                        // 动图（GIF/APNG/动态 WebP）
            'af'  => $anim ? (int)$a['f'] : 0,                             // 动图帧数
            'ad'  => ($anim && !empty($a['d'])) ? fmt_ms($a['d']) : '',     // 动图总时长
            'ap'  => ($anim && anim_can_mp4($path)) ? 'index.php?a=' . $key : '',  // 转码播放源；空 = 直接播原图
            'lv'  => ($pair !== '') ? 1 : 0,                               // 实况照片
            'lm'  => ($pair !== '') ? orig_url(rel_of($pair)) : '',         // 实况的动态部分（nginx 直出，支持分段）
            'ld'  => (!empty($lpv['dur']) ? fmt_ms($lpv['dur'] * 1000) : ''),   // 实况动态时长
            'w'   => $iw, 'h' => $ih,                                      // 原始尺寸
            'x'   => $key,                                                 // 元数据接口 key
            's'   => (int)@filesize($path),                                // 体积
            'm'   => (int)@filemtime($path),                               // 修改时间戳
        ];
        $items[]            = $it;
        $byAlbum[$name][]   = $it;
        $idx++;
    }
}
save_manifest(manifest());

ob_start();   // 捕获整页：先完整送达浏览器，再转入后台预热
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#f5f5f7">
<title><?= h($CONFIG['brand']) ?></title>
<meta name="description" content="<?= h($CONFIG['desc']) ?>">
<meta property="og:title" content="<?= h($CONFIG['brand']) ?>">
<meta property="og:description" content="<?= h($CONFIG['desc']) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23C79A4B'/><text x='16' y='23' font-size='20' text-anchor='middle' fill='white' font-family='sans-serif'>鎏</text></svg>">
<style>
/* ═══════════ 主题变量（浅色） ═══════════ */
:root{
  --bg:#f5f5f7; --surface:#ffffff; --surface2:#fafafa;
  --ink:#1d1d1f; --ink2:#6e6e73; --ink3:#8e8e93;
  --line:rgba(0,0,0,.08); --line2:rgba(0,0,0,.05);
  --nav:rgba(251,251,253,.78);
  --accent:#A8761F;                          /* 鎏金主色 */
  --gold1:#C79A4B; --gold2:#F0D9A0; --gold3:#B0822E; --gold4:#E9D3A0;
  --card-shadow:0 1px 2px rgba(0,0,0,.05),0 8px 24px rgba(0,0,0,.06);
  --card-shadow-hover:0 2px 6px rgba(0,0,0,.08),0 18px 44px rgba(0,0,0,.13);
  --cap:linear-gradient(180deg,transparent,rgba(0,0,0,.6));
  --sk1:#ececf0; --sk2:rgba(255,255,255,.65);
  --err1:#e8e8ed; --err2:#f2f2f6;
  --nav-h:120px;
  --font:-apple-system,BlinkMacSystemFont,"SF Pro Display","SF Pro Text",
         "PingFang SC","Hiragino Sans GB","Segoe UI","Microsoft YaHei",sans-serif;
  --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
/* ═══════════ 深色 ═══════════ */
html[data-theme="dark"]{
  --bg:#0d0d0f; --surface:#1c1c1e; --surface2:#232325;
  --ink:#f5f5f7; --ink2:#a1a1a6; --ink3:#8e8e93;
  --line:rgba(255,255,255,.1); --line2:rgba(255,255,255,.06);
  --nav:rgba(22,22,23,.72);
  --accent:#E3B463;
  --card-shadow:0 1px 2px rgba(0,0,0,.4),0 8px 24px rgba(0,0,0,.35);
  --card-shadow-hover:0 2px 8px rgba(0,0,0,.5),0 20px 48px rgba(0,0,0,.5);
  --sk1:#232325; --sk2:rgba(255,255,255,.07);
  --err1:#242426; --err2:#2c2c2e;
}
@media (prefers-color-scheme:dark){
  html:not([data-theme="light"]):not([data-theme="dark"]){
    --bg:#0d0d0f; --surface:#1c1c1e; --surface2:#232325;
    --ink:#f5f5f7; --ink2:#a1a1a6; --ink3:#8e8e93;
    --line:rgba(255,255,255,.1); --line2:rgba(255,255,255,.06);
    --nav:rgba(22,22,23,.72);
    --accent:#E3B463;
    --card-shadow:0 1px 2px rgba(0,0,0,.4),0 8px 24px rgba(0,0,0,.35);
    --card-shadow-hover:0 2px 8px rgba(0,0,0,.5),0 20px 48px rgba(0,0,0,.5);
    --sk1:#232325; --sk2:rgba(255,255,255,.07);
    --err1:#242426; --err2:#2c2c2e;
  }
}

/* ═══════════ 基础 reset 与全局 ═══════════ */
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:var(--bg);color:var(--ink);
  -webkit-font-smoothing:antialiased;transition:background .35s ease,color .35s ease}

/* ═══════════ 顶部鎏金加载光条 ═══════════ */
.gl{position:fixed;top:0;left:0;right:0;height:2px;z-index:300;pointer-events:none}
.gl i{display:block;height:100%;width:0;border-radius:0 2px 2px 0;
  background:linear-gradient(90deg,var(--gold3),var(--gold1),var(--gold2),var(--gold1));
  background-size:200% 100%;animation:sheen 2.4s linear infinite;
  box-shadow:0 0 10px rgba(199,154,75,.55);transition:width .35s ease,opacity .6s ease}
.gl.done i{opacity:0}
@keyframes sheen{from{background-position:0 0}to{background-position:200% 0}}

/* ═══════════ 毛玻璃顶栏 ═══════════ */
.nav{position:sticky;top:0;z-index:100;
  backdrop-filter:saturate(180%) blur(20px);-webkit-backdrop-filter:saturate(180%) blur(20px);
  background:var(--nav);border-bottom:1px solid var(--line)}
.nav-in{max-width:1560px;margin:0 auto;padding:12px 24px 0;display:flex;align-items:center;gap:14px;flex-wrap:wrap}

/* 品牌徽标 */
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;flex:0 0 auto}
.brand-mark{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;
  justify-content:center;font-size:17px;font-weight:600;color:#fff;
  background:linear-gradient(125deg,var(--gold3),var(--gold1) 30%,var(--gold2) 52%,var(--gold1) 70%,var(--gold3));
  background-size:220% 220%;animation:markShift 8s ease-in-out infinite;
  box-shadow:0 2px 10px rgba(176,130,46,.35),inset 0 1px 0 rgba(255,255,255,.5)}
@keyframes markShift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
.brand-name{font-size:19px;font-weight:700;letter-spacing:-.02em;white-space:nowrap}
.brand-name em{font-style:normal;font-weight:600;
  background:linear-gradient(100deg,var(--gold3),var(--gold1),var(--gold2),var(--gold1));
  background-size:200% 100%;-webkit-background-clip:text;background-clip:text;
  color:transparent;animation:sheen 6s linear infinite}
.brand-sub{font-size:11.5px;color:var(--ink3);margin-left:2px;font-weight:400}

.nav-spacer{margin-left:auto}
.nav-stat{font-size:12.5px;color:var(--ink2);font-variant-numeric:tabular-nums;white-space:nowrap}
.nav-stat b{color:var(--ink);font-weight:600}
.theme-btn{width:34px;height:34px;border-radius:50%;border:1px solid var(--line);
  background:var(--surface);cursor:pointer;font-size:15px;line-height:1;display:flex;
  align-items:center;justify-content:center;color:var(--ink);
  transition:transform .2s ease,box-shadow .2s ease}
.theme-btn:hover{transform:scale(1.08);box-shadow:var(--card-shadow)}

/* ═══════════ 工具栏 ═══════════ */
.tools{max-width:1560px;margin:0 auto;padding:10px 24px 12px;display:flex;
  align-items:center;gap:10px;flex-wrap:wrap}
.seg{display:inline-flex;background:var(--surface);border:1px solid var(--line);
  border-radius:10px;padding:2px;gap:2px}
.seg button{border:none;background:transparent;color:var(--ink2);font-family:inherit;
  font-size:12.5px;font-weight:500;padding:6px 12px;border-radius:8px;cursor:pointer;
  transition:background .2s ease,color .2s ease;white-space:nowrap}
.seg button:hover{color:var(--ink)}
.seg button.on{background:linear-gradient(120deg,var(--gold3),var(--gold1));
  color:#fff;font-weight:600;box-shadow:0 1px 6px rgba(176,130,46,.3)}
.search{position:relative;flex:1;min-width:170px;max-width:320px}
.search input{width:100%;font-family:inherit;font-size:13px;color:var(--ink);
  background:var(--surface);border:1px solid var(--line);border-radius:10px;
  padding:8px 30px 8px 30px;outline:none;transition:border-color .2s ease,box-shadow .2s ease}
.search input:focus{border-color:var(--gold1);box-shadow:0 0 0 3px rgba(199,154,75,.16)}
.search .ico{position:absolute;left:9px;top:50%;transform:translateY(-50%);
  font-size:13px;color:var(--ink3);pointer-events:none}
.search .clr{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;
  background:transparent;color:var(--ink3);cursor:pointer;font-size:14px;display:none;padding:2px 4px}
.search.has .clr{display:block}
select.sel{font-family:inherit;font-size:12.5px;color:var(--ink);background:var(--surface);
  border:1px solid var(--line);border-radius:10px;padding:8px 10px;cursor:pointer;outline:none}
.fav-btn{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;
  font-weight:500;color:var(--ink2);background:var(--surface);border:1px solid var(--line);
  border-radius:10px;padding:8px 13px;cursor:pointer;transition:all .2s ease;white-space:nowrap}
.fav-btn:hover{color:var(--ink)}
.fav-btn.on{color:#fff;border-color:transparent;
  background:linear-gradient(120deg,#D4574E,#E4746B)}
.fav-btn b{font-weight:600;font-variant-numeric:tabular-nums}

/* ═══════════ 相册胶囊 ═══════════ */
.chips{display:flex;gap:8px;overflow-x:auto;padding:2px 24px 14px;max-width:1560px;
  margin:0 auto;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex:0 0 auto;padding:6px 15px;border-radius:999px;font-size:13px;font-weight:500;
  color:var(--ink2);background:var(--surface);border:1px solid var(--line);
  text-decoration:none;transition:all .25s ease;white-space:nowrap;
  display:inline-flex;align-items:center;gap:6px}
.chip:hover{color:var(--ink);border-color:var(--gold1);transform:translateY(-1px)}
.chip b{font-weight:600;font-size:11.5px;color:var(--accent);font-variant-numeric:tabular-nums}

/* ═══════════ 相册分区 ═══════════ */
main{max-width:1560px;margin:0 auto;padding:4px 24px 80px}
.album{margin-top:40px;scroll-margin-top:calc(var(--nav-h) + 18px)}
.album-head{display:flex;align-items:baseline;gap:10px;margin-bottom:16px}
.album-head h2{font-size:25px;font-weight:700;letter-spacing:-.02em}
.album-head .n{font-size:13px;color:var(--ink2);font-weight:500;font-variant-numeric:tabular-nums}
.album-head::after{content:"";flex:1;height:1px;background:var(--line)}
.hide{display:none!important}   /* 通用隐藏（搜索/收藏筛选、模式切换都用它） */

/* ═══════════ 瀑布流（不规则紧密排列） ═══════════ */
.masonry{columns:4;column-gap:16px}
@media (max-width:1400px){.masonry{columns:3}}
@media (max-width:900px){.masonry{columns:2}}
@media (max-width:540px){.masonry{columns:1}}

.card{break-inside:avoid;-webkit-column-break-inside:avoid;margin-bottom:16px;
  position:relative;border-radius:16px;overflow:hidden;background:var(--surface);
  box-shadow:var(--card-shadow);cursor:zoom-in;
  transition:box-shadow .3s ease,transform .3s ease}
.card:hover{box-shadow:var(--card-shadow-hover)}

/* 骨架屏：布局由预置宽高锁定，图片就绪后淡入，零跳动 */
html.js .card img{opacity:0;transform:scale(1.015);
  transition:opacity .45s ease,transform .45s ease}
html.js .card img.ok{opacity:1;transform:none}
html.js .card:not(.loaded)::after{content:"";position:absolute;inset:0;z-index:1;
  background:linear-gradient(100deg,transparent 32%,var(--sk2) 50%,transparent 68%),var(--sk1);
  background-size:220% 100%;animation:shimmer 1.3s linear infinite}
@keyframes shimmer{from{background-position:130% 0}to{background-position:-130% 0}}

.card img{display:block;width:100%;height:auto;transform:scale(1);
  transition:transform .45s cubic-bezier(.2,.7,.3,1)}
.card:hover img{transform:scale(1.035)}
.card .cap{position:absolute;left:0;right:0;bottom:0;padding:34px 14px 11px;
  background:var(--cap);opacity:0;transition:opacity .3s ease;
  font-size:12.5px;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card:hover .cap{opacity:1}
@media (hover:none){.card .cap{opacity:1}}

/* 收藏按钮 */
.card .fav{position:absolute;top:10px;right:10px;width:30px;height:30px;border-radius:50%;
  border:1px solid rgba(255,255,255,.35);background:rgba(0,0,0,.3);color:#fff;
  backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
  font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;
  justify-content:center;opacity:0;transform:scale(.85);z-index:2;
  transition:opacity .25s ease,transform .2s ease,background .2s ease}
.card:hover .fav{opacity:1;transform:none}
@media (hover:none){.card .fav{opacity:1;transform:none}}
.card .fav:hover{background:rgba(0,0,0,.5);transform:scale(1.1)}
.card.fav .fav{opacity:1;transform:none;background:linear-gradient(120deg,#D4574E,#E4746B);
  border-color:transparent;color:#fff}

.card img.err{min-height:140px;object-fit:cover;background:
  repeating-linear-gradient(45deg,var(--err1) 0 10px,var(--err2) 10px 20px)}

/* 平铺视图（瀑布/网格）不显示元数据行，时间轴视图才显示 */
.card .meta{display:none}
.card .ex{display:none}

/* ═══════════ 视频卡片 ═══════════
 * 三件事：封面帧之上一个居中播放键（悬停淡入）、右下角时长角标、
 * 以及拿不到封面帧时的占位封面 —— 比例仍按真实视频尺寸，所以版面一样不跳。
 * 卡片永远只放静态封面，播放只发生在灯箱里（低配服务器 + 移动端的唯一稳解）。 */
.card .media{position:relative;display:block}
.card .vplay{position:absolute;left:50%;top:50%;z-index:2;padding-left:4px;
  width:52px;height:52px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:17px;color:#fff;background:rgba(0,0,0,.42);
  border:1px solid rgba(255,255,255,.34);
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
  box-shadow:0 4px 18px rgba(0,0,0,.32);
  opacity:0;transform:translate(-50%,-50%) scale(.9);
  transition:opacity .25s ease,transform .25s ease,background .2s ease}
.card:hover .vplay{opacity:1;transform:translate(-50%,-50%) scale(1)}
.card .vplay:hover{background:linear-gradient(120deg,var(--gold3),var(--gold1))}
@media (hover:none){.card .vplay{opacity:1;transform:translate(-50%,-50%) scale(1)}}

.card .vdur{position:absolute;right:10px;bottom:10px;z-index:3;pointer-events:none;
  font-family:var(--mono);font-size:11px;line-height:1;letter-spacing:.02em;
  color:#fff;background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.16);
  padding:4px 7px;border-radius:7px;font-variant-numeric:tabular-nums;
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}

/* ───── 动图角标：左下角一枚「动图」小片，可带时长 ───── */
.card .abadge{position:absolute;left:10px;bottom:10px;z-index:3;pointer-events:none;
  display:flex;align-items:center;gap:5px;
  font-size:11px;line-height:1;font-weight:600;letter-spacing:.02em;
  color:#fff;background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.18);
  padding:4px 8px;border-radius:7px;
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.card .abadge::before{content:"";width:6px;height:6px;border-radius:50%;
  background:var(--gold2);box-shadow:0 0 0 2px rgba(240,217,160,.28);
  animation:abPulse 1.7s ease-in-out infinite}
@keyframes abPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.45;transform:scale(.8)}}
@media (prefers-reduced-motion:reduce){.card .abadge::before{animation:none}}
.card .abadge i{font-style:normal;font-family:var(--font);font-size:10.5px;
  color:rgba(255,255,255,.72);font-variant-numeric:tabular-nums}  /* 含「秒」字，不能用 mono 栈（汉字会退成点阵体） */

/* ───── 实况照片徽标：左上角同心圆，对齐 iOS 原生观感 ───── */
.card .lvbadge{position:absolute;left:10px;top:10px;z-index:3;pointer-events:none;
  width:22px;height:22px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,.34);border:1px solid rgba(255,255,255,.28);
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.card .lvbadge i{width:13px;height:13px;border-radius:50%;
  border:1.6px solid rgba(255,255,255,.92);position:relative}
.card .lvbadge i::after{content:"";position:absolute;left:50%;top:50%;
  width:5.5px;height:5.5px;margin:-2.75px 0 0 -2.75px;border-radius:50%;
  background:#fff}

/* ───── 动图悬停预览：单实例覆盖层（同一时刻只有悬停那一张在播） ───── */
.card .hoverplay{position:absolute;inset:0;width:100%;height:100%;z-index:2;
  object-fit:cover;border-radius:inherit;animation:hpIn .26s ease;
  transition:opacity .26s ease;opacity:0}
.card.hp-on .hoverplay{opacity:1}
.card.hp-on .media > img:not(.hoverplay){opacity:0}      /* 原静态首帧让位 */
@keyframes hpIn{from{opacity:0}to{opacity:1}}
@media (hover:none){.card .hoverplay{display:none}}      /* 触屏不搞悬停预览 */

/* 占位封面（无 ffmpeg）：比例锁定，中心一个播放键，底下留一层微光流动 */
.card .vph{position:relative;display:flex;align-items:center;justify-content:center;
  width:100%;aspect-ratio:var(--ar,1.7778);
  background:linear-gradient(135deg,var(--sk1) 0%,var(--err2) 55%,var(--sk1) 100%)}
.card .vph::after{content:"";position:absolute;inset:0;opacity:.7;
  background:linear-gradient(100deg,transparent 34%,var(--sk2) 50%,transparent 66%);
  background-size:220% 100%;animation:shimmer 2.6s linear infinite}
.card .vph .vi{position:relative;z-index:2;padding-left:4px;
  width:52px;height:52px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:17px;color:#fff;background:rgba(0,0,0,.3);
  border:1px solid rgba(255,255,255,.32)}
html.js .card.nop:not(.loaded)::after{display:none}      /* 占位封面已有自己的光效，不要再叠骨架纹 */

/* ═══════════ 视图：等高网格（按行对齐 · 保留原始比例） ═══════════
 * 与瀑布流共用同一套卡片语言：16px 间隙、16px 圆角、同样的悬停与信息层，
 * 差别只在排版 —— 每行左右对齐、行内高度一致，图片不被裁成方块。
 * 原理：flex-basis 与 flex-grow 同时取 --ar（宽高比），
 *       卡片宽度差恰好等于宽高比差 → 同一行的高度自动相等，且整行铺满。 */
body.v-grid .masonry{columns:auto;display:flex;flex-wrap:wrap;gap:16px;--gh:224px}
body.v-grid .card{margin-bottom:0;aspect-ratio:var(--ar,1.5);
  flex-grow:var(--ar,1.5);flex-shrink:1;flex-basis:calc(var(--ar,1.5) * var(--gh))}
body.v-grid .card img{width:100%;height:100%;object-fit:cover}
body.v-grid .card .media{height:100%}
body.v-grid .card .vph{position:absolute;inset:0;width:100%;height:100%;aspect-ratio:auto}
/* 末行不拉伸：伪元素吸走剩余空间，只剩一两张时也保持同一行高 */
body.v-grid .masonry::after{content:"";flex:9999 1 0;height:0;align-self:flex-start}
@media (max-width:1400px){body.v-grid .masonry{--gh:200px}}
@media (max-width:900px) {body.v-grid .masonry{--gh:172px}}
@media (max-width:540px) {body.v-grid .masonry{--gh:134px}}

/* ═══════════ 视图：时间轴杂志 ═══════════
 * 按拍摄日期分组的编辑式长列表：左侧一条时间轴导轨 + 每组一个日期大字，
 * 图片保持原始比例居中（用 --ar × --mh 定宽，所以竖构图窄、横构图宽，天然有节奏），
 * 下方直接铺拍摄参数；入场带一点回弹。与瀑布/网格共用卡片、收藏、灯箱。 */
body.v-timeline .masonry{columns:auto;display:block;max-width:1000px;margin:0 auto;
  padding:0 28px;position:relative;--mh:min(52vh,500px)}   /* 左右同宽内边距：抵消左侧导轨占位，整列相对屏幕精确居中 */
/* 时间轴导轨 */
body.v-timeline .masonry::before{content:"";position:absolute;left:8px;top:8px;bottom:60px;width:1px;
  background:linear-gradient(180deg,var(--line) 0,var(--line) 62%,transparent 100%)}

/* 日期分组标题（吸顶毛玻璃，与顶栏同一套语言） */
body.v-timeline .tl-head{position:sticky;top:calc(var(--nav-h) - 12px);z-index:6;
  display:flex;align-items:baseline;gap:9px;
  margin:38px 0 20px;padding:9px 0 8px;
  background:linear-gradient(180deg,var(--bg) 72%,transparent);
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
body.v-timeline .tl-head:first-child{margin-top:4px}
body.v-timeline .tl-head .dd{font-size:32px;font-weight:700;line-height:.95;letter-spacing:-.04em;
  color:var(--ink);font-variant-numeric:tabular-nums}
body.v-timeline .tl-head .dd i{font-style:normal;font-size:12.5px;font-weight:500;
  color:var(--ink3);margin-left:3px;letter-spacing:0}
body.v-timeline .tl-head .ym{font-size:12.5px;font-weight:500;color:var(--ink2);
  font-variant-numeric:tabular-nums}
body.v-timeline .tl-head .ym em{font-style:normal;color:var(--ink3);margin-left:7px}
body.v-timeline .tl-head .n{margin-left:auto;font-size:11.5px;color:var(--ink3);
  font-variant-numeric:tabular-nums;white-space:nowrap}
/* 导轨上的鎏金节点 */
body.v-timeline .tl-head::before{content:"";position:absolute;left:-25px;top:15px;width:9px;height:9px;
  border-radius:50%;background:linear-gradient(125deg,var(--gold3),var(--gold1) 55%,var(--gold2));
  box-shadow:0 0 0 4px var(--bg),0 0 0 5px var(--line),0 2px 6px rgba(176,130,46,.45)}

/* 卡片：图片当"图片版"居中，文字落到纸面上 */
body.v-timeline .card{display:block;margin:0 auto 46px;background:transparent;box-shadow:none
  ;border-radius:0;overflow:visible;cursor:zoom-in;
  --iw:min(100%,calc(var(--ar,1.5) * var(--mh)));
  animation:tlIn .5s cubic-bezier(.2,.86,.28,1.04) both}
body.v-timeline .card img{width:100%;height:auto;border-radius:18px;
  box-shadow:var(--card-shadow);
  transition:transform .5s cubic-bezier(.2,.9,.2,1.08),box-shadow .35s ease,opacity .45s ease}
body.v-timeline .card:hover img{transform:translateY(-5px) scale(1.008);box-shadow:var(--card-shadow-hover)}
/* 画面容器收窄到图片宽度（--ar × --mh），文字与角标都跟着它对齐 */
body.v-timeline .card .media{width:var(--iw);margin:0 auto}
@keyframes tlIn{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
/* 错峰入场（卡片是 figure，分组标题是 div，nth-of-type 不会被标题带偏） */
body.v-timeline .card:nth-of-type(2){animation-delay:.035s}
body.v-timeline .card:nth-of-type(3){animation-delay:.07s}
body.v-timeline .card:nth-of-type(4){animation-delay:.105s}
body.v-timeline .card:nth-of-type(5){animation-delay:.14s}
body.v-timeline .card:nth-of-type(6){animation-delay:.175s}
body.v-timeline .card:nth-of-type(n+7){animation-delay:.2s}

html.js body.v-timeline .card::after{display:none}          /* 骨架纹改到图片自身上 */
html.js body.v-timeline .card img:not(.ok){background:
  linear-gradient(100deg,transparent 32%,var(--sk2) 50%,transparent 68%) var(--sk1);
  background-size:220% 100%;animation:shimmer 1.3s linear infinite}
body.v-timeline .card .cap{display:none}
/* 收藏键在 .media 里，直接贴画面右上角，不再需要按图片宽度反算偏移 */
body.v-timeline .card .fav{top:13px;right:12px}
/* 视频：占位封面铺满 .media；时长角标贴画面右下（时间轴另有金色时长参数，不重复） */
body.v-timeline .card .vph{width:100%;border-radius:18px;box-shadow:var(--card-shadow)}
body.v-timeline .card .vdur{display:none}
body.v-timeline .card .abadge{display:none}   /* 时间轴下方参数行已写明"动图 · N 帧 · 时长"，角标就重复了 */
body.v-timeline .card .vplay{width:58px;height:58px;font-size:19px}

/* 文件名 + 相册/体积/日期 */
body.v-timeline .card .meta{display:flex;flex-direction:column;gap:3px;margin-top:14px;padding:0 3px}
body.v-timeline .card .meta .mn{font-size:14.5px;font-weight:600;letter-spacing:-.01em;
  color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.v-timeline .card .meta .msub{font-size:12px;color:var(--ink3);font-variant-numeric:tabular-nums}

/* 拍摄参数行：照片下方直接铺开 */
body.v-timeline .card .ex{display:flex;flex-wrap:wrap;align-items:center;gap:6px;
  margin-top:11px;padding:0 3px;min-height:25px;opacity:.92;transition:opacity .3s ease}
body.v-timeline .card:hover .ex{opacity:1}
body.v-timeline .card .ex .k{font-size:11.5px;color:var(--ink2);background:var(--surface);
  border:1px solid var(--line2);border-radius:7px;padding:3px 8px;white-space:nowrap;
  font-variant-numeric:tabular-nums}
body.v-timeline .card .ex .k.cam{max-width:210px;overflow:hidden;text-overflow:ellipsis}
body.v-timeline .card .ex .k b{font-weight:600;color:var(--ink)}
body.v-timeline .card .ex .k.dur{color:var(--accent);border-color:rgba(168,118,31,.28);font-weight:600}
/* 动图 / 实况照片：鎏金描边的身份小片，一眼能从一排参数里分出来 */
body.v-timeline .card .ex .k.anim,
body.v-timeline .card .ex .k.live{color:var(--accent);font-weight:600;
  border-color:rgba(168,118,31,.34);
  background:linear-gradient(180deg,rgba(199,154,75,.1),rgba(199,154,75,.04))}
body.v-timeline .card .ex a.k{text-decoration:none;color:var(--accent);
  border-color:rgba(168,118,31,.3);background:linear-gradient(120deg,rgba(199,154,75,.1),transparent)}
body.v-timeline .card .ex a.k:hover{border-color:var(--gold1)}
body.v-timeline .card .ex .ph{font-size:11.5px;color:var(--ink3)}

@media (max-width:900px){
  body.v-timeline .masonry{--mh:min(74vh,560px);padding:0 22px}
  body.v-timeline .masonry::before{left:6px}
  body.v-timeline .tl-head::before{left:-20px}
  body.v-timeline .card{margin-bottom:38px}
}
@media (max-width:560px){
  body.v-timeline .masonry{--mh:min(118vw,520px);padding:0;max-width:100%}
  body.v-timeline .masonry::before,
  body.v-timeline .tl-head::before{display:none}
  body.v-timeline .tl-head{margin:28px 0 16px}
  body.v-timeline .tl-head .dd{font-size:27px}
  body.v-timeline .card{margin-bottom:32px}
  body.v-timeline .card img{border-radius:14px}
}

/* ═══════════ 空状态 ═══════════ */
.empty{margin:110px auto;text-align:center;color:var(--ink2);max-width:420px}
.empty .big{font-size:42px;margin-bottom:14px}
.empty p{font-size:13.5px;line-height:1.8}
.empty code{font-family:var(--mono);font-size:12.5px;background:var(--surface2);
  padding:2px 6px;border-radius:5px}

/* ═══════════ 回顶 ═══════════ */
.top{position:fixed;right:22px;bottom:26px;width:42px;height:42px;border-radius:50%;
  background:var(--surface);border:1px solid var(--line);color:var(--ink);
  font-size:16px;cursor:pointer;box-shadow:var(--card-shadow);z-index:90;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .3s ease,transform .2s ease}
.top.show{opacity:1;pointer-events:auto}
.top:hover{transform:translateY(-3px)}

/* ═══════════ 灯箱 ═══════════ */
.lb{position:fixed;inset:0;z-index:200;display:none;background:rgba(0,0,0,.94);
  backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
.lb.open{display:flex;flex-direction:column;animation:lbIn .22s ease}
@keyframes lbIn{from{opacity:0}to{opacity:1}}
.lb-stage{flex:1;display:flex;align-items:center;justify-content:center;
  padding:56px 64px 62px;min-height:0;overflow:auto}
.lb-stage img{max-width:100%;max-height:100%;border-radius:10px;
  box-shadow:0 24px 80px rgba(0,0,0,.6),0 0 0 1px rgba(199,154,75,.22);
  user-select:none;animation:imgIn .3s ease;cursor:zoom-in;transition:transform .25s ease}
@keyframes imgIn{from{opacity:0;transform:scale(.97)}to{opacity:1;transform:none}}
.lb-stage img.zoomed{max-width:none;max-height:none;width:auto;height:auto;
  cursor:zoom-out;border-radius:4px}
/* 灯箱里的视频：与图片同一套尺寸约束与投影；播放交给浏览器原生控制条 */
.lb-stage video{max-width:100%;max-height:100%;border-radius:10px;background:#000;
  outline:none;animation:imgIn .3s ease;
  box-shadow:0 24px 80px rgba(0,0,0,.6),0 0 0 1px rgba(199,154,75,.22)}
.lb-stage video[hidden]{display:none}
.lb-stage.on-video{padding:58px 24px 64px}          /* 视频横屏偏大，上下留够、左右收一点 */

/* 实况照片：按住时动态层顶替静帧（尺寸由 JS 按静帧实测像素对齐，切换零跳动） */
.lb-stage.live-on #lbImg{display:none}
.lb-livetag{position:absolute;left:50%;transform:translateX(-50%);bottom:54px;z-index:5;
  display:flex;align-items:center;gap:7px;padding:7px 13px 8px;border-radius:999px;
  overflow:hidden;white-space:nowrap;max-width:min(92vw,420px);
  color:rgba(255,255,255,.86);font-size:12.5px;
  background:rgba(28,28,30,.72);border:1px solid rgba(255,255,255,.14);
  backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  transition:border-color .2s ease}
.lb-livetag[hidden]{display:none}
.lb-livetag.play{border-color:rgba(199,154,75,.5)}
.lb-livetag .ic{width:13px;height:13px;border-radius:50%;flex:0 0 auto;position:relative;
  border:1.5px solid rgba(255,255,255,.85)}
.lb-livetag .ic::after{content:"";position:absolute;left:50%;top:50%;
  width:5px;height:5px;margin:-2.5px 0 0 -2.5px;border-radius:50%;background:#fff}
.lb-livetag b{font-weight:500}
.lb-livetag > i{font-style:normal;font-family:var(--font);font-size:11.5px;
  color:rgba(255,255,255,.5)}
.lb-livetag .bar{position:absolute;left:0;right:0;bottom:0;height:2px;
  background:rgba(255,255,255,.12)}
.lb-livetag .bar i{display:block;height:100%;width:0;
  background:linear-gradient(90deg,var(--gold3),var(--gold1));transition:width .12s linear}

.lb-bar{position:absolute;top:0;left:0;right:0;display:flex;align-items:center;
  gap:12px;padding:13px 20px;color:rgba(255,255,255,.88);font-size:13.5px;
  background:linear-gradient(180deg,rgba(0,0,0,.5),transparent);z-index:3}
.lb-bar .t{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.lb-bar .a{color:rgba(255,255,255,.5);font-size:12.5px;flex:0 0 auto}
.lb-bar .sp{margin-left:auto}
.lb-btn{width:36px;height:36px;border-radius:50%;border:1px solid rgba(255,255,255,.2);
  background:rgba(255,255,255,.1);color:#fff;font-size:15px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex:0 0 auto;
  transition:background .2s ease,transform .2s ease}
.lb-btn:hover{background:rgba(255,255,255,.22);transform:scale(1.06)}
.lb-btn.act{background:linear-gradient(120deg,var(--gold3),var(--gold1));border-color:transparent}
.lb-btn.favd{background:linear-gradient(120deg,#D4574E,#E4746B);border-color:transparent}
.lb-arrow{position:absolute;top:50%;transform:translateY(-50%);width:46px;height:46px;font-size:19px;z-index:3}
.lb-prev{left:14px}.lb-next{right:14px}
.lb-foot{position:absolute;bottom:14px;left:0;right:0;text-align:center;
  color:rgba(255,255,255,.5);font-size:12px;letter-spacing:.04em;z-index:3}
.lb-foot kbd{font-family:var(--mono);font-size:11px;background:rgba(255,255,255,.12);
  border-radius:4px;padding:1px 5px;margin:0 1px}

/* 灯箱信息面板 */
.lb-info{position:absolute;top:0;right:0;bottom:0;width:336px;z-index:4;
  background:rgba(18,18,20,.94);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);
  border-left:1px solid rgba(255,255,255,.1);
  transform:translateX(101%);transition:transform .3s cubic-bezier(.2,.7,.3,1);
  overflow-y:auto;padding:60px 22px 28px;color:rgba(255,255,255,.9);font-size:13px}
.lb-info.open{transform:none}
@media (max-width:760px){
  .lb-info{width:100%;top:auto;height:62%;border-left:none;
    border-top:1px solid rgba(255,255,255,.12);border-radius:16px 16px 0 0;
    transform:translateY(101%);padding-top:18px}
  .lb-info.open{transform:none}
}
.lb-info h4{font-size:11.5px;font-weight:600;letter-spacing:.1em;color:var(--gold2);
  text-transform:uppercase;margin:20px 0 8px;opacity:.9}
.lb-info h4:first-child{margin-top:0}
.irow{display:flex;gap:12px;padding:6.5px 0;border-bottom:1px solid rgba(255,255,255,.06);
  align-items:flex-start}
.irow:last-child{border-bottom:none}
.ik{flex:0 0 74px;color:rgba(255,255,255,.48);font-size:12.5px}
.iv{flex:1;word-break:break-all;line-height:1.6;font-size:12.5px}
.iv.mono{font-family:var(--mono);font-size:11.5px;letter-spacing:-.01em}
.lb-info .ph{color:rgba(255,255,255,.35);font-size:12.5px;padding:14px 0;text-align:center}
.lb-acts{display:flex;gap:8px;margin-top:20px;flex-wrap:wrap}
.lb-acts a,.lb-acts button{flex:1;min-width:104px;text-align:center;text-decoration:none;
  font-family:inherit;font-size:12.5px;font-weight:500;padding:9px 12px;border-radius:9px;
  cursor:pointer;border:1px solid rgba(255,255,255,.18);color:#fff;
  background:rgba(255,255,255,.08);transition:background .2s ease}
.lb-acts a:hover,.lb-acts button:hover{background:rgba(255,255,255,.18)}
.lb-acts .prim{background:linear-gradient(120deg,var(--gold3),var(--gold1));
  border-color:transparent;color:#fff}
.lb-acts .prim:hover{background:linear-gradient(120deg,var(--gold1),var(--gold2))}

/* ═══════════ 轻提示 ═══════════ */
.toast{position:fixed;left:50%;bottom:34px;transform:translate(-50%,20px);
  background:rgba(28,28,30,.94);color:#fff;font-size:13px;padding:10px 18px;
  border-radius:11px;z-index:400;opacity:0;pointer-events:none;
  border:1px solid rgba(199,154,75,.4);
  transition:opacity .25s ease,transform .25s ease}
.toast.show{opacity:1;transform:translate(-50%,0)}

/* ═══════════ 页脚 ═══════════ */
footer{text-align:center;padding:32px 20px 54px;color:var(--ink3);font-size:12px;line-height:2}
footer .fb{display:inline-flex;align-items:center;gap:7px;justify-content:center}
footer .dot{width:5px;height:5px;border-radius:50%;
  background:linear-gradient(120deg,var(--gold1),var(--gold2))}
footer kbd{font-family:var(--mono);font-size:11px;background:var(--surface);
  border:1px solid var(--line);border-radius:4px;padding:1px 5px}

/* ═══════════ 窄屏适配（≤760px：顶栏加高、搜索独占一行、灯箱边距收紧） ═══════════ */
@media (max-width:760px){
  :root{--nav-h:186px}
  .nav-in{padding:10px 16px 0}
  .tools{padding:9px 16px 11px;gap:8px}
  .search{max-width:none;order:9;flex:1 1 100%}
  .chips{padding:2px 16px 12px}
  main{padding:4px 16px 70px}
  .brand-name{font-size:17px}
  .brand-sub{display:none}
  .lb-stage{padding:56px 12px 66px}
  .lb-arrow{width:40px;height:40px}
}
/* ═══════════ 首访鎏光开场（仅 html.splash-on 时显示，由内联脚本在绘制前打标） ═══════════ */
#splash{display:none}
html.splash-on #splash{display:flex;position:fixed;inset:0;z-index:9999;background:var(--bg);
  flex-direction:column;align-items:center;justify-content:center;gap:18px;
  opacity:1;transition:opacity .6s ease;overflow:hidden}
html.splash-on body{overflow:hidden}
#splash.sp-done{opacity:0;pointer-events:none}
/* 背景呼吸金晕 + 谢幕时的全屏鎏金闪光 */
.sp-aura{position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(46% 46% at 50% 44%,rgba(199,154,75,.20),transparent 72%);
  animation:spAura 2.4s ease-in-out infinite}
#splash::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:0;
  background:radial-gradient(circle at 50% 44%,rgba(255,244,214,.95),rgba(199,154,75,.38) 42%,transparent 74%)}
#splash.sp-done::after{animation:spFlash .6s ease-out}
/* 舞台：旋转光束 + 双环 + 三轨粒子 + 鎏金「鎏」 */
.sp-stage{position:relative;width:150px;height:150px;animation:spRise .55s cubic-bezier(.2,.9,.3,1.2) both}
.sp-beam{position:absolute;inset:-48px;border-radius:50%;
  background:conic-gradient(from 0deg,transparent 0deg,rgba(199,154,75,.32) 58deg,transparent 128deg);
  filter:blur(17px);animation:spSpin 2.7s linear infinite}
.sp-ring{position:absolute;inset:0;border-radius:50%;
  background:conic-gradient(from 0deg,transparent 0 34%,var(--gold2) 50%,#fff3d0 62%,var(--accent) 74%,transparent 92%);
  -webkit-mask:radial-gradient(farthest-side,transparent calc(100% - 4px),#000 calc(100% - 3px));
  mask:radial-gradient(farthest-side,transparent calc(100% - 4px),#000 calc(100% - 3px));
  animation:spSpin 1.35s cubic-bezier(.62,.08,.38,.92) infinite;
  filter:drop-shadow(0 0 12px rgba(199,154,75,.75))}
.sp-r2{inset:13px;opacity:.85;animation:spSpin 9s linear infinite reverse;
  background:repeating-conic-gradient(from 0deg,rgba(199,154,75,.9) 0deg 2.2deg,transparent 2.2deg 15deg);
  -webkit-mask:radial-gradient(farthest-side,transparent calc(100% - 2px),#000 calc(100% - 1px));
  mask:radial-gradient(farthest-side,transparent calc(100% - 2px),#000 calc(100% - 1px));
  filter:none}
.sp-orbit{position:absolute;inset:0;animation:spSpin 1.6s linear infinite;pointer-events:none}
.sp-orbit i{position:absolute;top:-3px;left:50%;width:7px;height:7px;margin-left:-3.5px;border-radius:50%;
  background:#ffe9b0;box-shadow:0 0 9px 3px rgba(232,201,126,.85)}
.sp-o2{inset:-17px;animation-duration:2.4s;animation-direction:reverse}
.sp-o2 i{width:5px;height:5px;top:-2px;opacity:.75}
.sp-o3{inset:24px;animation-duration:1.1s}
.sp-o3 i{width:4px;height:4px;opacity:.55}
.sp-mark{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  font-size:48px;font-weight:700;
  background:linear-gradient(100deg,#96712e 0%,var(--gold2) 32%,#fff8e0 50%,var(--gold2) 68%,#96712e 100%);
  background-size:230% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;
  animation:spSheen 1.9s ease-in-out infinite;
  filter:drop-shadow(0 0 14px rgba(199,154,75,.55))}
/* 标题：鎏金扫光字 */
.sp-name{font-size:22px;font-weight:600;letter-spacing:.14em;
  background:linear-gradient(100deg,var(--ink) 22%,var(--gold2) 44%,#fff8e0 50%,var(--gold2) 56%,var(--ink) 78%);
  background-size:220% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;
  animation:spSheen 2.8s ease-in-out infinite,spFadeUp .6s .12s ease both}
.sp-name em{font-style:normal}
.sp-sub{font-size:11px;letter-spacing:.34em;color:var(--ink3);text-transform:uppercase;
  animation:spFadeUp .6s .22s ease both}
.sp-load{position:relative;width:210px;height:3px;border-radius:2px;background:var(--line);
  overflow:hidden;animation:spFadeUp .6s .3s ease both}
.sp-load i{display:block;height:100%;width:0;border-radius:2px;
  background:linear-gradient(90deg,var(--gold3),var(--gold2),var(--gold3));
  animation:spBar 2.1s ease-out forwards}
.sp-load::after{content:"";position:absolute;inset:0;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.7),transparent);
  transform:translateX(-110%);animation:spSweep 1.3s ease-in-out infinite}
.sp-tip{font-size:12px;color:var(--ink3);animation:spFadeUp .6s .38s ease both}
/* 谢幕：环放大旋散、其余元素先行隐去 */
#splash.sp-done .sp-stage{animation:spOut .6s cubic-bezier(.5,0,.8,.4) forwards}
#splash.sp-done .sp-name,#splash.sp-done .sp-sub,
#splash.sp-done .sp-load,#splash.sp-done .sp-tip{opacity:0;transition:opacity .28s}
@keyframes spSpin{to{transform:rotate(360deg)}}
@keyframes spAura{0%,100%{opacity:.65;transform:scale(1)}50%{opacity:1;transform:scale(1.12)}}
@keyframes spSheen{0%{background-position:125% 0}100%{background-position:-125% 0}}
@keyframes spSweep{to{transform:translateX(110%)}}
@keyframes spBar{0%{width:0}70%{width:78%}100%{width:92%}}
@keyframes spFlash{0%{opacity:0}22%{opacity:1}100%{opacity:0}}
@keyframes spRise{0%{transform:scale(.55);opacity:0}100%{transform:scale(1);opacity:1}}
@keyframes spFadeUp{0%{transform:translateY(10px);opacity:0}100%{transform:translateY(0);opacity:1}}
@keyframes spOut{to{transform:scale(1.75) rotate(46deg);opacity:0;filter:blur(7px)}}
/* 浅色主题：光束/光晕降透明度、金字加深底色，保证白底上对比度 */
html[data-theme="light"] .sp-aura{background:radial-gradient(46% 46% at 50% 44%,rgba(150,110,35,.15),transparent 72%)}
html[data-theme="light"] .sp-beam{background:conic-gradient(from 0deg,transparent 0deg,rgba(160,118,42,.26) 58deg,transparent 128deg)}
html[data-theme="light"] .sp-mark{filter:drop-shadow(0 0 8px rgba(150,110,35,.32));
  background:linear-gradient(100deg,#6d5013 0%,#a8802f 32%,#e8c877 50%,#a8802f 68%,#6d5013 100%);
  background-size:230% 100%;-webkit-background-clip:text;background-clip:text}
html[data-theme="light"] .sp-orbit i{background:#c39a3e;box-shadow:0 0 7px 2px rgba(180,140,60,.55)}
@media (prefers-reduced-motion:reduce){
  .sp-ring,.sp-r2,.sp-orbit,.sp-beam,.sp-aura,.sp-load::after{animation:none}
  .sp-mark,.sp-name{animation:spFadeUp .4s ease both}
}
</style>
<script>
/* 首访鎏光开场标记：必须在 body 绘制前执行，否则开场层会闪现。
 * localStorage 记一次之后直达内容；想再看一遍用 ?splash（?splash=hold 定格不消失）。 */
try {
  var spQ = /[?&]splash(?:=(\w+))?/.exec(location.search);
  if (spQ || !localStorage.getItem('lg_splash')) document.documentElement.classList.add('splash-on');
} catch (e) {}
</script>
</head>
<body>

<div id="splash" aria-hidden="true">
  <div class="sp-aura"></div>
  <div class="sp-stage">
    <div class="sp-beam"></div>
    <div class="sp-ring"></div>
    <div class="sp-ring sp-r2"></div>
    <div class="sp-orbit sp-o1"><i></i></div>
    <div class="sp-orbit sp-o2"><i></i></div>
    <div class="sp-orbit sp-o3"><i></i></div>
    <div class="sp-mark">鎏</div>
  </div>
  <div class="sp-name">鎏光<em>MrdT</em>相册</div>
  <div class="sp-sub">Liuguang MrdT Gallery</div>
  <div class="sp-load"><i></i></div>
  <div class="sp-tip">正在加速载入照片…</div>
</div>

<div class="gl" id="gl"><i></i></div>   <!-- 顶部鎏金加载光条（宽度由 Loader 按已载比例驱动） -->

<script>
document.documentElement.classList.add('js');   // 有 JS 才启用骨架屏淡入（无 JS 时图片直接显示）
/* 加载失败三级降级：缩略图 → 原图 → 占位纹（必须定义在任何 <img> 之前）
 * 视频封面走另一条：封面帧失败 → 直接换成占位封面（绝不把 mp4 当图片去试） */
window.__imgErr = function (im) {
  var c = im.closest('.card');
  var isVid = c && c.dataset.kind === 'video';
  if (!isVid && !im.dataset.fallback && im.dataset.orig) {
    im.dataset.fallback = '1';
    im.onload = function () {
      im.classList.add('ok');
      var c2 = im.closest('.card'); if (c2) c2.classList.add('loaded');
    };
    im.src = im.dataset.orig;
    return;
  }
  if (isVid && c && !c.querySelector('.vph')) {
    window.__videoPlaceholder(c, im);                      // 封面失败 → 占位封面
    return;
  }
  im.classList.add('err', 'ok');
  if (c) c.classList.add('loaded');
};
/* 把卡片里的 <img> 换成占位封面（与 PHP 侧无 ffmpeg 时的渲染结果一致） */
window.__videoPlaceholder = function (card, img) {
  var m = card.querySelector('.media') || card;
  var ph = document.createElement('div');
  ph.className = 'vph';
  ph.innerHTML = '<span class="vi">▶</span>';
  if (img && img.parentNode === m) m.replaceChild(ph, img);
  else m.insertBefore(ph, m.firstChild);
  var pl = card.querySelector('.vplay'); if (pl) pl.parentNode.removeChild(pl);
  card.classList.add('nop', 'loaded');
};
/* 轻提示 */
window.__toast = function (msg) {
  var t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  clearTimeout(window.__tt);
  window.__tt = setTimeout(function () { t.classList.remove('show'); }, 1900);
};
</script>

<nav class="nav">
  <!-- 第一行：品牌徽标 · 全站统计 · 主题切换 -->
  <div class="nav-in">
    <a class="brand" href="#top">
      <span class="brand-mark">鎏</span>
      <span class="brand-name">鎏光<em>MrdT</em>相册</span>
    </a>
    <span class="brand-sub">Liuguang MrdT Gallery</span>
    <span class="nav-spacer"></span>
    <span class="nav-stat" id="stat"><b><?= $total ?></b> <?= $nMix ? '项' : '张' ?><?= $mix ? '（含 ' . h(implode(' · ', $mix)) . '）' : '' ?> · <b><?= count($albums) ?></b> 册 · <b><?= h(human_size($totalBytes)) ?></b> · 已载 <b id="loaded">0</b></span>
    <button class="theme-btn" id="themeBtn" title="切换主题（跟随系统 / 浅色 / 深色）">🌗</button>
  </div>

  <!-- 第二行：工具栏（模式 / 搜索 / 排序 / 视图 / 收藏筛选） -->
  <div class="tools">
    <div class="seg" id="modeSeg">
      <button data-mode="albums" class="on">按相册</button>
      <button data-mode="all">全部照片</button>
    </div>

    <div class="search" id="searchBox">
      <span class="ico">🔍</span>
      <input id="q" type="search" placeholder="搜索文件名或相册…" autocomplete="off">
      <button class="clr" id="qClr" title="清空">✕</button>
    </div>

    <select class="sel" id="sort" title="排序方式">
      <option value="name-asc">名称 ↑</option>
      <option value="name-desc">名称 ↓</option>
      <option value="date-desc">时间 ↓（新→旧）</option>
      <option value="date-asc">时间 ↑（旧→新）</option>
      <option value="size-desc">体积 ↓（大→小）</option>
      <option value="size-asc">体积 ↑（小→大）</option>
    </select>

    <div class="seg" id="viewSeg">
      <button data-view="masonry" class="on">瀑布</button>
      <button data-view="grid">网格</button>
      <button data-view="timeline">时间轴</button>
    </div>

    <button class="fav-btn" id="favBtn">♡ 收藏 <b id="favCount">0</b></button>
  </div>
</nav>

<!-- 相册胶囊：横向滑动，点击平滑滚动到对应分区 -->
<div class="chips" id="chips">
<?php $si = 0; foreach ($albums as $name => $files): $si++; ?>
  <a class="chip" href="#sec-<?= $si ?>" data-goto="<?= $si ?>"><?= h($name) ?><b><?= $albumStat[$name]['n'] ?></b></a>
<?php endforeach; ?>
</div>

<main id="top">
<?php if (!$total): ?>
  <div class="empty">
    <div class="big">🗂️</div>
    <p>相册还是空的。<br>把照片、动图、视频（或包含它们的文件夹）放到本文件所在目录，刷新即可。</p>
    <p style="margin-top:14px;font-size:12.5px;color:var(--ink3)">
      支持 GIF / APNG / 动态 WebP 动图，以及 iPhone 实况照片（同名 HEIC/JPG + MOV 自动配对）<br>
      缩略图缓存目录：<code>.cache</code>　　预热命令：<code>php index.php --warm</code>
    </p>
  </div>
<?php endif; ?>

<div id="noResult" class="empty hide">
  <div class="big">🔍</div>
  <p>没有符合条件的照片。<br>试试换个关键词，或取消收藏筛选。</p>
</div>

<?php $si = 0;
/* 相册分区渲染：每个一级子文件夹一个 <section>，卡片按 $byAlbum 逐张输出。
 * 卡片上的 data-* 属性是前端全部行为的载体：搜索词、排序键、灯箱索引、类型标记。 */
foreach ($albums as $name => $files): $si++; ?>
  <section class="album" id="sec-<?= $si ?>">
    <div class="album-head">
      <h2><?= h($name) ?></h2>
      <span class="n"><?= $albumStat[$name]['n'] ?> <?= $albumStat[$name]['mix'] ? '项' : '张' ?></span>
    </div>
    <div class="masonry">
<?php foreach (($byAlbum[$name] ?? []) as $it):
        // --ar：展示宽高比。等高网格用它算每张卡片的宽度（宽高比越大 → 该行里越宽），
        // 极端比例做钳制，避免超窄长图把一行撑爆（多出来的部分由 object-fit 裁掉）。
        // 尺寸完全读不到时（孤立 HEIC：浏览器与 GD 都不认，又没有配对 MOV）用中性方形，
        // 别让 max(0.3,…) 的兜底把它压成一根 1:3.3 的窄条。
        $ar  = ($it['w'] > 0 && $it['h'] > 0)
             ? round(max(0.3, min(4.2, $it['w'] / $it['h'])), 4)
             : 1.0;
        $d   = $it['m'] ? date('Y-m-d', $it['m']) : '';
        $vid = ($it['k'] === 'video');
        $mix = $vid || $it['an'] || $it['lv'];
        // 视频 + 本机抽不了帧 → 不渲染 <img>（省掉必然失败的请求），直接给占位封面
        $noPoster = $vid && $it['np'];
        // 搜索词带上类型关键词：搜「视频 / 动图 / 实况」能把它们筛出来
        $kw = lower($name . ' ' . $it['n']);
        if ($vid)          $kw .= ' 视频 video ' . ext_of($it['n']);
        elseif ($it['an']) $kw .= ' 动图 动画 gif';
        if ($it['lv'])     $kw .= ' 实况 live';
?>
      <figure class="card<?= $vid ? ' is-video' : '' ?><?= $noPoster ? ' nop' : '' ?><?= $it['an'] ? ' is-anim' : '' ?><?= $it['lv'] ? ' is-live' : '' ?>"
              data-idx="<?= $it['i'] ?>" style="--ar:<?= $ar ?>"
              data-key="<?= h($it['x']) ?>"
              data-kind="<?= $it['k'] ?>"
              data-anim="<?= $it['an'] ?>"
              data-live="<?= $it['lv'] ?>"
              data-mix="<?= $mix ? 1 : 0 ?>"
              data-s="<?= h($kw) ?>"
              data-name="<?= h($it['n']) ?>"
              data-mtime="<?= (int)$it['m'] ?>"
              data-size="<?= (int)$it['s'] ?>">
        <!-- .media：画面的定位容器。播放键/时长角标/徽标/收藏键都锚在画面上，
             时间轴视图里卡片下方还有文字，若锚在整卡上就会飘到文字区 -->
        <div class="media">
<?php if ($noPoster): ?>
          <div class="vph"><span class="vi">▶</span></div>
<?php else: ?>
          <img alt="<?= h($it['n']) ?>"
               width="<?= (int)$it['w'] ?>" height="<?= (int)$it['h'] ?>"
               decoding="async"
               data-src="<?= h($it['t']) ?>"
               data-orig="<?= h($it['o']) ?>" onerror="__imgErr(this)">
          <noscript><img src="<?= h($it['t']) ?>" loading="lazy" decoding="async"
               width="<?= (int)$it['w'] ?>" height="<?= (int)$it['h'] ?>"
               alt="<?= h($it['n']) ?>"></noscript>
<?php endif; ?>
<?php if ($vid): ?>
          <?php if (!$noPoster): ?><span class="vplay" aria-hidden="true">▶</span><?php endif; ?>
          <?php if ($it['dt']): ?><span class="vdur"><?= h($it['dt']) ?></span><?php endif; ?>
<?php endif; ?>
<?php if ($it['an']): ?>
          <span class="abadge" title="动图 · 点开即播，鼠标悬停预览">动图<?= $it['ad'] ? '<i>' . h($it['ad']) . '</i>' : '' ?></span>
<?php endif; ?>
<?php if ($it['lv']): ?>
          <span class="lvbadge" title="实况照片 · 点开后按住画面播放"><i></i></span>
<?php endif; ?>
          <button class="fav" title="收藏 / 取消收藏" aria-label="收藏">♡</button>
        </div>
        <figcaption class="cap"><?= h($it['n']) ?></figcaption>
        <div class="meta">
          <span class="mn"><?= h($it['n']) ?></span>
          <span class="msub"><?= h($name) ?> · <?= h(human_size($it['s'])) ?><?= $d ? ' · ' . h($d) : '' ?></span>
        </div>
        <div class="ex"></div>
      </figure>
<?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

  <!-- 全部照片模式下的统一容器（JS 将卡片移动至此） -->
  <section class="album hide" id="flatSec">
    <div class="album-head"><h2><?= $nMix ? '全部作品' : '全部照片' ?></h2><span class="n" id="flatN"><?= $total ?> <?= $nMix ? '项' : '张' ?></span></div>
    <div class="masonry" id="flatMasonry"></div>
  </section>
</main>

<button class="top" id="topBtn" title="回到顶部">↑</button>

<div class="lb" id="lb">
  <!-- 顶栏：相册名 / 文件名 / 收藏 / 信息 / 关闭 -->
  <div class="lb-bar">
    <span class="a" id="lbAlbum"></span>
    <span class="t" id="lbName"></span>
    <span class="sp"></span>
    <button class="lb-btn" id="lbFav" title="收藏 / 取消收藏 (F)">♡</button>
    <button class="lb-btn" id="lbInfoBtn" title="文件信息与拍摄参数 (I)">ⓘ</button>
    <button class="lb-btn" id="lbClose" title="关闭 (Esc)">✕</button>
  </div>
  <div class="lb-stage" id="lbStage">
    <img id="lbImg" alt="">
    <video id="lbVid" playsinline controls preload="metadata" hidden></video>
    <!-- 实况照片的动态层：按住画面时替换静帧播放（尺寸按静帧实测像素对齐，切换无跳动） -->
    <video id="lbLive" playsinline muted preload="none" hidden></video>
    <div class="lb-livetag" id="lbLiveTag" hidden>
      <span class="ic"></span><b id="lbLiveTx">按住看动态</b><i id="lbLiveDur"></i>
      <span class="bar"><i id="lbLiveBar"></i></span>
    </div>
  </div>
  <button class="lb-btn lb-arrow lb-prev" id="lbPrev" title="上一张 (←)">‹</button>
  <button class="lb-btn lb-arrow lb-next" id="lbNext" title="下一张 (→)">›</button>
  <div class="lb-foot" id="lbCount"></div>

  <!-- 右侧滑出的信息面板（文件信息 + 拍摄参数 + 下载/复制直链） -->
  <aside class="lb-info" id="lbInfo">
    <div id="lbInfoBody"><div class="ph">载入中…</div></div>
  </aside>
</div>

<div class="toast" id="toast"></div>

<footer>
  <div class="fb">
    <span class="dot"></span>
    <span><?= h($CONFIG['brand']) ?> · 单文件驱动 · 无数据库</span>
  </div>
  <div>
    灯箱快捷键　<kbd>←</kbd> <kbd>→</kbd> 切换　<kbd>空格</kbd> 播放/暂停　按住画面看实况　<kbd>I</kbd> 信息　<kbd>F</kbd> 收藏　<kbd>Esc</kbd> 关闭
  </div>
</footer>

<script>
/* ═══════════════════════════════════════════════════
   鎏光MrdT相册 · 前端逻辑
   ═══════════════════════════════════════════════════ */
/* ───── 服务端注入的数据 ─────
 * ITEMS：全站媒体清单（灯箱索引 i 与数组下标一一对应，卡片 data-idx 即此下标）
 * TOTAL：媒体总数（顶部光条与「已载 N」的分母） */
var ITEMS    = <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var TOTAL    = <?= (int)$total ?>;

/* ───── 主题：自动(跟随系统) → 浅色 → 深色 ───── */
(function () {
  var btn = document.getElementById('themeBtn'), root = document.documentElement;
  var modes = ['auto', 'light', 'dark'], icons = {auto: '🌗', light: '☀️', dark: '🌙'};
  var cur = localStorage.getItem('lg_theme') || 'auto';
  apply(cur);
  btn.addEventListener('click', function () {
    cur = modes[(modes.indexOf(cur) + 1) % 3];
    localStorage.setItem('lg_theme', cur); apply(cur);
  });
  function apply(m) {
    btn.textContent = icons[m];
    if (m === 'auto') root.removeAttribute('data-theme');
    else root.setAttribute('data-theme', m);
  }
})();

/* ───── 收藏（localStorage 持久化） ───── */
var Favs = (function () {
  var KEY = 'lg_favs', set;
  try { set = JSON.parse(localStorage.getItem(KEY) || '[]'); }
  catch (e) { set = []; }
  if (!(set instanceof Array)) set = [];
  var idx = {};
  set.forEach(function (k) { idx[k] = 1; });
  function save() { try { localStorage.setItem(KEY, JSON.stringify(Object.keys(idx))); } catch (e) {} }
  return {
    has: function (k) { return !!idx[k]; },
    toggle: function (k) {
      if (idx[k]) { delete idx[k]; } else { idx[k] = 1; }
      save(); return !!idx[k];
    },
    count: function () { return Object.keys(idx).length; }
  };
})();

/* ───── 状态 ───── */
var S = { q: '', sort: 'name-asc', view: 'masonry', mode: 'albums', favOnly: false };

/* 初次进入的视图：?view=timeline 之类的链接优先，其次用上次记住的（两者都没有就用瀑布流） */
(function () {
  var m = /[?&]view=(masonry|grid|timeline)(?:&|$)/.exec(location.search);
  var v = m ? m[1] : '';
  if (!v) { try { v = localStorage.getItem('lg_view') || ''; } catch (e) { v = ''; } }
  if (['masonry', 'grid', 'timeline'].indexOf(v) < 0) return;
  S.view = v;
  document.body.classList.add('v-' + v);
  Array.prototype.forEach.call(document.querySelectorAll('#viewSeg button'), function (b) {
    b.classList.toggle('on', b.dataset.view === v);
  });
})();

/* 顶栏真实高度 → --nav-h（分组标题吸顶、相册锚点跳转都靠它） */
(function () {
  var nav = document.querySelector('.nav');
  if (!nav) return;
  function sync() {
    var h = nav.offsetHeight;
    if (h) document.documentElement.style.setProperty('--nav-h', h + 'px');
  }
  sync();
  window.addEventListener('resize', sync);
  window.addEventListener('load', sync);
})();

/* ───── 全局 DOM 引用 ───── */
var cards       = Array.prototype.slice.call(document.querySelectorAll('.card'));   // 全部卡片（静态 NodeList 快照）
var albumSecs   = Array.prototype.slice.call(document.querySelectorAll('.album'))
                    .filter(function (s) { return s.id !== 'flatSec'; });            // 相册分区（不含"全部"容器）
var flatSec     = document.getElementById('flatSec');        // 「全部照片」模式的容器
var flatBox     = document.getElementById('flatMasonry');    // 「全部照片」模式的卡片盒子
var homeParent  = new Map();     // 记录每张卡片的原始容器，便于从"全部"模式还原

/* ───── 动图悬停预览 ─────
 * 只在"鼠标停留的那一张"上播：同一时刻最多一个解码实例，几十张动图同屏也不炸内存。
 * 有转码 MP4 就播 <video>（体积小、移动端也流畅），没有就把原动图铺上去（动画零损耗）。
 * 触屏设备不启用（@media (hover:none) 里已把覆盖层隐藏），点开灯箱看即可。 */
var Hover = (function () {
  var vEl = null, el = null, cur = null, ok = false;
  try { ok = window.matchMedia('(hover:hover) and (pointer:fine)').matches; } catch (e) { ok = false; }
  if (!ok) return { bind: function () {} };

  function stop() {
    if (el) {
      if (el.tagName === 'VIDEO') { el.pause(); el.removeAttribute('src'); try { el.load(); } catch (e) {} }
      if (el.parentNode) el.parentNode.removeChild(el);
    }
    if (cur) cur.classList.remove('hp-on');
    el = null; cur = null;
  }
  function show() { if (cur) cur.classList.add('hp-on'); }
  function play(card) {
    if (cur === card) return;
    var it = ITEMS[+card.dataset.idx];
    var m  = card.querySelector('.media');
    if (!it || !it.an || !m) return;
    stop();
    cur = card;
    if (it.ap) {                                   // 转码 MP4：复用同一个 <video>
      vEl = vEl || document.createElement('video');
      el = vEl;
      el.muted = true; el.loop = true; el.playsInline = true;
      el.setAttribute('muted', ''); el.setAttribute('playsinline', '');
      el.preload = 'auto'; el.className = 'hoverplay';
    } else {                                       // 原动图直接铺：浏览器原生播放
      el = document.createElement('img');
      el.className = 'hoverplay'; el.alt = '';
    }
    el.addEventListener('error', function () { stop(); }, {once: true});
    m.appendChild(el);
    el.src = it.ap || it.o;
    if (el.tagName === 'VIDEO') {
      el.addEventListener('loadeddata', show, {once: true});
      var pr = el.play();
      if (pr && pr.catch) pr.catch(function () {});
    } else {
      el.addEventListener('load', show, {once: true});
      if (el.complete) show();                     // 命中缓存时 load 可能已经过去了
    }
  }
  return {
    bind: function () {
      cards.forEach(function (c) {
        if (c.dataset.anim !== '1') return;
        c.addEventListener('mouseenter', function () { play(c); });
        c.addEventListener('mouseleave', stop);
      });
    }
  };
})();
Hover.bind();

/* ───── 有序加载器：封面优先 → 视口顺序 → 并发上限 ───── */
var Loader = (function () {
  var MAX = 6, loading = 0, queue = [], loaded = 0, paused = false;
  var bar = document.querySelector('#gl i'), gl = document.getElementById('gl');
  var loadedEl = document.getElementById('loaded');

  function pump() {
    if (paused) return;                       // 灯箱打开期间暂停，带宽让给大图
    while (loading < MAX && queue.length) {
      queue.sort(function (a, b) { return a._prio - b._prio; });
      loadOne(queue.shift());
    }
  }
  /** 计入进度：占位封面这类没有 <img> 的卡片也要计数，否则顶部光条会永远停住 */
  function tick() {
    loaded++;
    if (loadedEl) loadedEl.textContent = loaded;
    if (bar && TOTAL > 0) {
      bar.style.width = Math.min(100, loaded / TOTAL * 100) + '%';
      if (loaded >= TOTAL) gl.classList.add('done');
    }
  }
  function loadOne(card) {
    var img = card.querySelector('img');
    if (!img || img.src || img.dataset.src === undefined) {
      if (!img) tick();                       // 无封面帧的视频卡片：直接算已载
      pump();
      return;
    }
    loading++;
    function done() {
      loading--;
      tick();
      pump();
    }
    img.addEventListener('load', function () { img.classList.add('ok'); card.classList.add('loaded'); done(); }, {once: true});
    img.addEventListener('error', function () { done(); }, {once: true});
    img.src = img.dataset.src;
  }
  function enqueue(card, prio) {
    if (!card || card._queued) return;
    card._queued = 1; card._prio = prio; queue.push(card); pump();
  }
  function pause()  { paused = true; }
  function resume() { paused = false; pump(); }
  /** 首访开场光环期间把并发拉满：光环遮屏的两秒多正好全速预载首屏 */
  function boost(n) { MAX = Math.max(MAX, n || 12); pump(); }

  if (!('IntersectionObserver' in window)) {          // 极老浏览器：全量入队
    cards.forEach(function (c) { enqueue(c, +c.dataset.idx); });
    return { enqueue: enqueue, pause: pause, resume: resume, boost: boost };
  }

  // 1) 每个相册前 2 张作为封面，立即并行加载 → 多个文件夹同时出图
  albumSecs.forEach(function (sec, si) {
    Array.prototype.slice.call(sec.querySelectorAll('.card')).forEach(function (c, ci) {
      if (ci < 2) enqueue(c, -1000 + si * 10 + ci);
    });
  });

  // 2) 其余：进入视口前 700px 时按文档顺序入队（有序、无惊群）
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      if (e.isIntersecting) { enqueue(e.target, +e.target.dataset.idx); io.unobserve(e.target); }
    });
  }, {rootMargin: '700px', threshold: .01});
  cards.forEach(function (c) { io.observe(c); });

  return { enqueue: enqueue, pause: pause, resume: resume, boost: boost };
})();

/* ───── 元数据缓存（批量拉取） ─────
 * 时间轴视图一屏要铺十几组拍摄参数，一张一个请求会打出几十个往返；
 * 这里把同一时刻要的 key 合并成 ?x=k1,k2,... 一次取回（服务端单批上限 60）。 */
var Meta = (function () {
  var cache = {}, pending = {}, queue = [], timer = 0, CHUNK = 24;

  function flush() {
    timer = 0;
    var keys = queue.splice(0, CHUNK);
    if (!keys.length) return;
    if (queue.length) timer = setTimeout(flush, 40);          // 还有排队，接着下一批
    var url = 'index.php?x=' + keys.map(encodeURIComponent).join(',');
    fetch(url, {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) { settle(keys, (d && d.items) || null); })
      .catch(function () { settle(keys, null); });
  }
  function settle(keys, map) {
    keys.forEach(function (k) {
      var m = (map && map[k]) || {ok: false};
      cache[k] = m;
      var wait = pending[k]; delete pending[k];
      if (wait) wait.forEach(function (fn) { fn(m); });
    });
  }
  return {
    peek: function (k) { return cache[k] || null; },
    get: function (k, cb) {
      if (cache[k]) { cb(cache[k]); return; }
      (pending[k] = pending[k] || []).push(cb);
      if (queue.indexOf(k) < 0) queue.push(k);
      if (!timer) timer = setTimeout(flush, 30);
    }
  };
})();

/* ───── 时间轴视图：按日期分组 + 照片下方铺参数 ───── */
var Timeline = (function () {
  var WD  = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
  var KEY = [['相机', 'cam'], ['镜头', ''], ['焦距', ''], ['光圈', ''], ['快门', ''],
             ['感光度', ''], ['曝光补偿', ''], ['拍摄位置', 'gps']];
  var VKEY = ['时长', '分辨率', '帧率', '视频编码', '音频', '码率', '容器', '创建时间'];   // 视频参数行的顺序
  var io = null, ioReady = false;

  function on() { return S.view === 'timeline'; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }
  /** 卡片所属日期（与"时间"排序同一口径：文件时间） */
  function dayKey(c) {
    var t = (+c.dataset.mtime) * 1000;
    if (!t) return '0';
    var d = new Date(t);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }
  function boxes() {
    if (S.mode === 'all') return [flatBox];
    return albumSecs.map(function (s) { return s.querySelector('.masonry'); })
                    .filter(function (b) { return !!b; });
  }

  function headEl(day, cards) {
    var count = cards.length;
    var nv = 0;
    cards.forEach(function (c) { if (c.dataset.kind === 'video') nv++; });
    var unit = nv ? '项' : '张';
    var el = document.createElement('div');
    el.className = 'tl-head';
    if (day === '0') {
      el.innerHTML = '<span class="ym">未标注日期</span><span class="n">' + count + ' ' + unit + '</span>';
      return el;
    }
    var p = day.split('-'), d = +p[2];
    var wd = WD[new Date(+p[0], +p[1] - 1, d).getDay()];
    el.innerHTML = '<span class="dd">' + d + '<i>日</i></span>'
                 + '<span class="ym">' + p[0] + ' 年 ' + (+p[1]) + ' 月<em>' + wd + '</em></span>'
                 + '<span class="n">' + count + ' ' + unit + '</span>';
    return el;
  }

  /** 重建全部日期标题（排序/筛选/切视图后调用，先清后建，保证不留空标题） */
  function refresh() {
    Array.prototype.forEach.call(document.querySelectorAll('.tl-head'), function (h) {
      if (h.parentNode) h.parentNode.removeChild(h);
    });
    if (!on()) return;
    boxes().forEach(function (box) {
      var vis = Array.prototype.slice.call(box.children).filter(function (n) {
        return n.classList && n.classList.contains('card') && !n.classList.contains('hide');
      });
      var groups = [], last = null;
      vis.forEach(function (c) {
        var k = dayKey(c);
        if (!last || last.k !== k) { last = {k: k, cards: []}; groups.push(last); }
        last.cards.push(c);
      });
      groups.forEach(function (g) {
        box.insertBefore(headEl(g.k, g.cards), g.cards[0]);
      });
    });
  }

  /** 渲染某张卡片下方的参数行 */
  function renderEx(card, d) {
    var box = card.querySelector('.ex');
    if (!box) return;

    // 视频：换成时长/分辨率/编码那一套（服务端已按同一"键=>值"结构给出）
    if (d && d.ok && d.kind === 'video') {
      var vex = d.exif || {}, vout = '', vn = 0;
      VKEY.forEach(function (k) {
        if (!vex[k]) return;
        vout += '<span class="k' + (k === '时长' ? ' dur' : '') + '" title="' + esc(k) + '">'
              + esc(vex[k]) + '</span>';
        vn++;
      });
      box.innerHTML = vn ? vout : '<span class="ph">这个视频没有可读的媒体信息</span>';
      return;
    }

    var ex = (d && d.ok && d.exif) ? d.exif : {};
    var out = '', n = 0;

    // 动图 / 实况照片：先在参数行最前面点明身份，再铺后面的常规参数
    var pre = '';
    if (d && d.ok && d.anim) {
      pre += '<span class="k anim" title="动图（GIF / APNG / 动态 WebP）">动图'
           + (d.animFrames > 1 ? ' · ' + d.animFrames + ' 帧' : '')
           + (d.animDur ? ' · ' + esc(d.animDur) : '')
           + '</span>';
    }
    if (d && d.ok && d.live) {
      pre += '<span class="k live" title="iPhone 实况照片 · 点开后按住画面播放动态">实况'
           + (d.liveDur ? ' · ' + esc(d.liveDur) : '') + '</span>';
    }

    KEY.forEach(function (kv) {
      var v = ex[kv[0]];
      if (!v) return;
      if (kv[1] === 'gps') {                                  // GPS → 可点开地图
        var s = String(v).split(','), lat = parseFloat(s[0]), lng = parseFloat(s[1]);
        if (!isFinite(lat) || !isFinite(lng)) return;
        var u = 'https://uri.amap.com/marker?position=' + lng + ',' + lat
              + '&coordinate=gps&name=' + encodeURIComponent('拍摄位置') + '&callnative=0';
        out += '<a class="k gps" target="_blank" rel="noopener" href="' + u
             + '" title="GPS 拍摄位置（WGS84），点开在手机地图查看">📍 '
             + lat.toFixed(4) + ', ' + lng.toFixed(4) + '</a>';
      } else {
        out += '<span class="k' + (kv[1] ? ' ' + kv[1] : '') + '" title="' + kv[0] + '">'
             + esc(v) + '</span>';
      }
      n++;
    });

    // 拍摄时间：和分组日期同一天就只显示时刻，跨天/跨年再逐步补全（一眼看出与文件时间的出入）
    var dt = ex['拍摄时间'];
    if (dt) {
      var s2 = String(dt), d2 = s2.slice(0, 10), hm = s2.slice(11, 16), cur2 = dayKey(card);
      out += '<span class="k" title="拍摄时间 ">' + esc(
        d2 === cur2 ? hm
        : (d2.slice(0, 4) === cur2.slice(0, 4) ? d2.slice(5) + ' ' + hm : d2)) + '</span>';
      n++;
    }

    if (!n) {                                                 // 没有参数：给尺寸，别留空白
      if (d && d.ok && d.w && d.h) out += '<span class="k">' + d.w + ' × ' + d.h + '</span>';
      if (!pre) out += '<span class="ph">' + (d && d.ok ? '这张图没有拍摄参数（截图或转发后已清除 EXIF）'
                                                        : '参数不可用') + '</span>';
    }
    box.innerHTML = pre + out;
  }

  function loadEx(card) {
    var k = card.dataset.key;
    if (!k) return;
    var hit = Meta.peek(k);
    if (hit) { renderEx(card, hit); return; }
    Meta.get(k, function (d) { renderEx(card, d); });
  }

  /** 进入时间轴：建分组 + 让参数行只在该视图里按需拉取 */
  function activate() {
    refresh();
    if (!ioReady) {
      ioReady = true;
      if ('IntersectionObserver' in window) {
        io = new IntersectionObserver(function (es) {
          es.forEach(function (e) {
            if (!e.isIntersecting) return;
            io.unobserve(e.target);
            if (on()) loadEx(e.target);
          });
        }, {rootMargin: '600px 0px'});
      }
    }
    if (!io) { cards.forEach(loadEx); return; }               // 老浏览器：直接全量
    cards.forEach(function (c) {
      if (!Meta.peek(c.dataset.key)) io.observe(c);
    });
  }

  return {refresh: refresh, activate: activate};
})();

/* ───── 视图 / 模式 / 检索 / 排序 ───── */

/** 模式切换：按相册分区 ←→ 全部照片平铺 */
function ensureMode(m) {
  if (m === S.mode) return;
  if (m === 'all') {
    cards.forEach(function (c) {
      if (!homeParent.has(c)) homeParent.set(c, c.parentNode);
      flatBox.appendChild(c);
    });
    albumSecs.forEach(function (s) { s.classList.add('hide'); });
    flatSec.classList.remove('hide');
  } else {
    cards.forEach(function (c) {
      var p = homeParent.get(c);
      if (p) p.appendChild(c);
    });
    albumSecs.forEach(function (s) { s.classList.remove('hide'); });
    flatSec.classList.add('hide');
  }
  S.mode = m;
  checkEmpty();
}

/** 排序：名称 / 时间 / 体积 */
function applySort() {
  var parts = S.sort.split('-'), field = parts[0], mul = parts[1] === 'desc' ? -1 : 1;
  var boxes = (S.mode === 'all') ? [flatBox] : Array.prototype.slice.call(document.querySelectorAll('.masonry'))
                                                          .filter(function (b) { return b !== flatBox; });
  boxes.forEach(function (box) {
    var kids = Array.prototype.slice.call(box.children).filter(function (n) { return n.classList.contains('card'); });
    kids.sort(function (a, b) {
      var r = 0;
      if (field === 'name')      r = a.dataset.name.localeCompare(b.dataset.name, 'zh-Hans-CN', {numeric: true});
      else if (field === 'date') r = (+a.dataset.mtime) - (+b.dataset.mtime);
      else if (field === 'size') r = (+a.dataset.size) - (+b.dataset.size);
      if (r === 0) r = (+a.dataset.idx) - (+b.dataset.idx);
      return r * mul;
    });
    kids.forEach(function (k) { box.appendChild(k); });
  });
  Timeline.refresh();          // 排序会打乱分组标题的位置，重建一次
}

/** 过滤：关键词 + 收藏，并隐藏空相册 */
function applyFilter() {
  var q = S.q;
  var visCount = 0, visMix = 0;
  cards.forEach(function (c) {
    var okQ   = !q || (c.dataset.s || '').indexOf(q) >= 0;
    var okFav = !S.favOnly || c.classList.contains('fav');
    var vis   = okQ && okFav;
    c.classList.toggle('hide', !vis);
    if (vis) { visCount++; if (c.dataset.mix === '1') visMix++; }
  });
  albumSecs.forEach(function (s) {
    var any = Array.prototype.slice.call(s.querySelectorAll('.card'))
              .some(function (c) { return !c.classList.contains('hide'); });
    s.classList.toggle('hide', !any);
  });
  var fn = document.getElementById('flatN');
  if (fn) fn.textContent = visCount + ' ' + (visMix ? '项' : '张');
  Timeline.refresh();          // 被筛掉的日期不留空标题
  checkEmpty(visCount);
}

/** 空结果提示：一张都不剩时显示「没有符合条件的照片」 */
function checkEmpty(visCount) {
  if (visCount === undefined) {
    visCount = cards.filter(function (c) { return !c.classList.contains('hide'); }).length;
  }
  document.getElementById('noResult').classList.toggle('hide', visCount > 0 || TOTAL === 0);
}

/** 同步卡片收藏态 */
function syncFav(card) {
  var k = card.dataset.key;
  card.classList.toggle('fav', Favs.has(k));
  var b = card.querySelector('.fav');
  if (b) b.textContent = Favs.has(k) ? '♥' : '♡';
}
cards.forEach(syncFav);
document.getElementById('favCount').textContent = Favs.count();

/* 收藏按钮点击（不触发灯箱） */
cards.forEach(function (c) {
  var b = c.querySelector('.fav');
  if (!b) return;
  b.addEventListener('click', function (e) {
    e.stopPropagation();
    var on = Favs.toggle(c.dataset.key);
    syncFav(c);
    document.getElementById('favCount').textContent = Favs.count();
    __toast(on ? '已加入收藏' : '已取消收藏');
    if (S.favOnly) applyFilter();
  });
});

/* 工具栏事件 */
document.getElementById('modeSeg').addEventListener('click', function (e) {
  var b = e.target.closest('button'); if (!b) return;
  this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
  ensureMode(b.dataset.mode);
  applySort(); applyFilter();
});
document.getElementById('viewSeg').addEventListener('click', function (e) {
  var b = e.target.closest('button'); if (!b) return;
  this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
  S.view = b.dataset.view;
  try { localStorage.setItem('lg_view', S.view); } catch (e) {}
  document.body.classList.remove('v-masonry', 'v-grid', 'v-list', 'v-timeline');
  document.body.classList.add('v-' + S.view);

  // 时间轴要顺着时间读，否则同一天的照片会被别的排序打散成分散的小段
  syncSortOpts();              // 时间排序选项只在时间轴挂载
  if (S.view === 'timeline' && S.sort.indexOf('date') !== 0) {
    S.sort = 'date-desc';
    document.getElementById('sort').value = S.sort;
    __toast('时间轴：已切换为按时间 ↓ 排序');
  }
  applySort();
  Timeline.activate();
});

var qEl = document.getElementById('q'), qBox = document.getElementById('searchBox');
var qTimer;
qEl.addEventListener('input', function () {
  qBox.classList.toggle('has', !!qEl.value);
  clearTimeout(qTimer);
  qTimer = setTimeout(function () { S.q = qEl.value.trim().toLowerCase(); applyFilter(); }, 130);
});
document.getElementById('qClr').addEventListener('click', function () {
  qEl.value = ''; qBox.classList.remove('has'); S.q = ''; applyFilter(); qEl.focus();
});
/* 时间排序只在时间轴视图提供：其余视图把 date-* 选项从下拉中摘掉，
 * 当前排序若正好是时间序则回退到名称 ↑（detach/append 重排，规避 option 隐藏的兼容坑） */
var sortEl = document.getElementById('sort');
var sortAllOpts = Array.prototype.slice.call(sortEl.options);
function syncSortOpts() {
  var isTl = S.view === 'timeline';
  sortEl.innerHTML = '';
  sortAllOpts.forEach(function (o) {
    if (!isTl && o.value.indexOf('date') === 0) return;
    sortEl.appendChild(o);
  });
  if (!isTl && S.sort.indexOf('date') === 0) S.sort = 'name-asc';
  sortEl.value = S.sort;
}
syncSortOpts();
document.getElementById('sort').addEventListener('change', function () {
  S.sort = this.value; applySort();
});
document.getElementById('favBtn').addEventListener('click', function () {
  S.favOnly = !S.favOnly;
  this.classList.toggle('on', S.favOnly);
  applyFilter();
  __toast(S.favOnly ? '只看收藏' : '显示全部');
});

/* 胶囊跳转：在"全部"模式下先切回相册模式 */
document.getElementById('chips').addEventListener('click', function (e) {
  var a = e.target.closest('.chip'); if (!a) return;
  e.preventDefault();
  var si = a.dataset.goto;
  if (S.mode !== 'albums') {
    document.querySelector('#modeSeg button[data-mode="albums"]').click();
    S.q = ''; qEl.value = ''; qBox.classList.remove('has');
    S.favOnly = false; document.getElementById('favBtn').classList.remove('on');
    applyFilter();
  }
  var sec = document.getElementById('sec-' + si);
  if (sec) sec.scrollIntoView({behavior: 'smooth', block: 'start'});
});

/* ───── 回到顶部 ───── */
(function () {
  var btn = document.getElementById('topBtn');
  window.addEventListener('scroll', function () {
    btn.classList.toggle('show', window.scrollY > 600);
  }, {passive: true});
  btn.addEventListener('click', function () { window.scrollTo({top: 0, behavior: 'smooth'}); });
})();

/* ───── 灯箱 ───── */
(function () {
  var lb    = document.getElementById('lb'),
      img   = document.getElementById('lbImg'),
      vid   = document.getElementById('lbVid'),
      stage = document.getElementById('lbStage'),
      name  = document.getElementById('lbName'),
      alb   = document.getElementById('lbAlbum'),
      cnt   = document.getElementById('lbCount'),
      infoP = document.getElementById('lbInfo'),
      infoB = document.getElementById('lbInfoBody'),
      favB  = document.getElementById('lbFav'),
      infoBtn = document.getElementById('lbInfoBtn'),
      lvv   = document.getElementById('lbLive'),
      lvTag = document.getElementById('lbLiveTag'),
      lvTx  = document.getElementById('lbLiveTx'),
      lvDur = document.getElementById('lbLiveDur'),
      lvBar = document.getElementById('lbLiveBar'),
      cur = -1, preloadCache = {}, curSrc = '',
      liveSrc = '', liveIt = null, liveOn = false;

  /* ── 实况照片：按住画面播动态、松手回到静帧（对齐 iOS 原生手感） ──
   * 动态层不是叠在静帧上，而是"顶替"静帧：按住时把静帧 display:none，
   * 视频按静帧的实测像素设成同样大小再显示 —— 两者比例一致，切换时零跳动。 */
  function liveReset() {
    if (!lvv) return;
    liveOn = false;
    lvv.pause();
    try { lvv.currentTime = 0; } catch (e) {}
    lvv.hidden = true;
    lvv.removeAttribute('style');
    stage.classList.remove('live-on');
    if (lvTag) lvTag.classList.remove('play');
    if (lvBar) lvBar.style.width = '0%';
  }
  function liveHide() {
    liveReset();
    liveIt = null;
    if (lvv) { lvv.removeAttribute('src'); }
    if (lvTag) lvTag.hidden = true;
  }
  function liveShow(it) {
    if (!lvv || !it.lm) { liveHide(); return; }      // 没有动态源 → 退化成普通静帧
    liveIt = it;
    lvTag.hidden = false;
    if (lvTx)  lvTx.textContent  = '按住看动态';
    if (lvDur) lvDur.textContent = it.ld || '';
    if (liveSrc !== it.lm) {                         // 换了一条实况才重设源，同一条不打断
      liveSrc = it.lm;
      lvv.src = it.lm;
      lvv.load();
    }
    liveReset();
  }
  function livePlay() {
    if (!liveIt || !lvv || liveOn) return;
    var r = img.getBoundingClientRect();
    if (r.width > 1) {                               // 对齐静帧的实际渲染尺寸，切换无跳动
      lvv.style.width  = Math.round(r.width) + 'px';
      lvv.style.height = Math.round(r.height) + 'px';
    }
    liveOn = true;
    lvv.hidden = false;
    stage.classList.add('live-on');
    lvTag.classList.add('play');
    if (lvTx) lvTx.textContent = '松手回到照片';
    var pr = lvv.play();
    if (pr && pr.catch) pr.catch(function () {       // 自动播放策略兜底：静音再试
      lvv.muted = true;
      var q = lvv.play(); if (q && q.catch) q.catch(function () {});
    });
  }
  if (lvv) {
    lvv.loop = false;                                // 播完 3 秒自动回到静帧
    lvv.addEventListener('timeupdate', function () {                 // 顶部一条细进度
      if (!lvBar || !lvv.duration) return;
      lvBar.style.width = Math.min(100, lvv.currentTime / lvv.duration * 100) + '%';
    });
    lvv.addEventListener('ended', function () { liveReset(); if (lvTx) lvTx.textContent = '按住看动态'; });
  }

  function show(i) {
    if (i < 0) i = ITEMS.length - 1;
    if (i >= ITEMS.length) i = 0;
    cur = i;
    var it     = ITEMS[i];
    var isVid  = (it.k === 'video');
    var isAnim = !!it.an;
    // 播放源：视频用原片直链（nginx 出 206）；动图有转码就播 MP4，没有就直接铺原动图
    var vSrc   = isVid ? it.o : (isAnim && it.ap ? it.ap : '');

    name.textContent = it.n;
    alb.textContent  = (isVid ? '🎬 ' : (it.lv ? '◉ ' : (isAnim ? '🎞 ' : '📁 '))) + it.a;
    cnt.textContent  = (i + 1) + ' / ' + ITEMS.length;
    favB.textContent = Favs.has(it.x) ? '♥' : '♡';
    favB.classList.toggle('favd', Favs.has(it.x));

    if (vSrc) {
      /* 原生播放器：视频用原片直链、动图用转码 MP4，都由 nginx/PHP 出 206 分段。
         同一条重开不重设 src，保留播放进度。 */
      liveHide();
      img.hidden = true;
      img.classList.remove('zoomed');
      stage.classList.add('on-video');
      if (img.getAttribute('src') !== null) img.removeAttribute('src');
      vid.hidden = false;
      vid.loop   = isAnim;                    // 动图＝循环播放，贴近 GIF 的原生观感
      vid.muted  = isAnim;                    // （自动播放的硬性前提）
      if (isAnim) vid.setAttribute('autoplay', ''); else vid.removeAttribute('autoplay');
      if (it.np || !it.v) vid.removeAttribute('poster'); else vid.poster = it.v;
      if (curSrc !== vSrc) {
        vid.pause();
        vid.src = vSrc;
        vid.load();
        curSrc = vSrc;
        if (isAnim) { var pp = vid.play(); if (pp && pp.catch) pp.catch(function () {}); }
      }
    } else {
      vid.hidden = true;
      stage.classList.remove('on-video');
      if (curSrc !== '') { vid.pause(); vid.removeAttribute('src'); vid.load(); curSrc = ''; }
      img.hidden = false;
      delete img.dataset.fallback;
      img.classList.remove('err', 'zoomed');
      img.dataset.orig = it.o;
      // 动图没有转码源时直接加载原图 —— 浏览器会原生把它动起来，动画零损耗
      img.src = isAnim ? it.o : it.v;
      if (it.lv) liveShow(it); else liveHide();
      // 相邻预载只对图片做：视频不预载，避免白白吃掉带宽
      if (!preloadCache[it.v]) { var p = new Image(); p.src = it.v; preloadCache[it.v] = 1; }
      [ (i + 1) % ITEMS.length, (i - 1 + ITEMS.length) % ITEMS.length ].forEach(function (j) {
        var nx = ITEMS[j];
        if (nx.k === 'video') return;
        if (!preloadCache[nx.v]) { var q = new Image(); q.src = nx.v; preloadCache[nx.v] = 1; }
      });
    }

    if (infoP.classList.contains('open')) loadMeta(it);
  }

  /* 元数据面板：走共享缓存（时间轴可能已经拉过了，直接用，不再请求） */
  function loadMeta(it) {
    var hit = Meta.peek(it.x);
    if (hit) { renderMeta(hit); return; }
    infoB.innerHTML = '<div class="ph">载入中…</div>';
    Meta.get(it.x, function (d) { renderMeta(d); });
  }

  function row(k, v, mono) {
    return '<div class="irow"><span class="ik">' + esc(k) + '</span><span class="iv'
         + (mono ? ' mono' : '') + '">' + esc(v) + '</span></div>';
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }

  function renderMeta(d) {
    if (!d || !d.ok) { infoB.innerHTML = '<div class="ph">信息不可用</div>'; return; }
    var isV = (d.kind === 'video');
    var type = isV ? '视频'
             : (d.anim ? ('动图' + (d.animFrames > 1 ? ' · ' + d.animFrames + ' 帧' : '')
                          + (d.animMP4 ? '（转码播放）' : '（原图画质）'))
             : (d.live ? '实况照片' + (d.liveDur ? ' · 动态 ' + d.liveDur : '') : '图片'));
    var h = '';
    h += '<h4>文件</h4>';
    h += row('文件名', d.name);
    h += row('所属相册', d.album);
    h += row('类型', type);
    h += row('尺寸', (d.w && d.h) ? (d.w + ' × ' + d.h + ' px') : '未知');
    h += row('体积', d.size);
    h += row('修改时间', d.mtime || '—');
    h += row('相对路径', d.path, true);

    var ex = d.exif || {};
    var keys = Object.keys(ex);
    h += '<h4>' + (isV ? '视频信息' : '拍摄参数') + '</h4>';
    if (keys.length) {
      keys.forEach(function (k) { h += row(k, ex[k]); });
    } else {
      h += '<div class="ph">' + (isV
        ? '这个视频没有可读的媒体信息<br>（容器格式不支持解析，或用例外的编码）'
        : '该图片没有可读的拍摄参数<br>（截图、社交软件转发或已清除 EXIF）') + '</div>';
    }

    var abs = new URL(d.url, location.href).href;
    h += '<div class="lb-acts">'
       + '<a class="prim" href="' + esc(d.url) + '" download="' + esc(d.name) + '">'
       + (isV ? '⬇ 下载视频' : (d.live ? '⬇ 下载照片' : (d.anim ? '⬇ 下载原动图' : '⬇ 下载原图'))) + '</a>'
       + (d.liveUrl ? '<a href="' + esc(d.liveUrl) + '" download>⬇ 动态片段</a>' : '')
       + '<button type="button" id="lbCopy" data-u="' + esc(abs) + '">🔗 复制直链</button>'
       + '</div>';
    infoB.innerHTML = h;

    var cp = document.getElementById('lbCopy');
    if (cp) cp.addEventListener('click', function () {
      var u = cp.dataset.u;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(u).then(
          function () { __toast('直链已复制'); },
          function () { __toast(u); });
      } else {
        var ta = document.createElement('textarea');
        ta.value = u; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); __toast('直链已复制'); } catch (e) { __toast(u); }
        document.body.removeChild(ta);
      }
    });
  }

  function toggleInfo(force) {
    var open = (force === undefined) ? !infoP.classList.contains('open') : force;
    infoP.classList.toggle('open', open);
    infoBtn.classList.toggle('act', open);
    if (open && cur >= 0) loadMeta(ITEMS[cur]);
  }

  function open(i) {
    show(i);
    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
    Loader.pause();                                  // 带宽让给灯箱大图
  }
  function close() {
    lb.classList.remove('open');
    document.body.style.overflow = '';
    img.src = '';
    img.classList.remove('zoomed');
    vid.pause();                                    // 关灯箱必须停片并释放缓冲，否则留着后台跑
    vid.removeAttribute('src');
    vid.load();
    curSrc = '';
    liveHide();                                     // 实况动态层同样要停掉
    Loader.resume();
  }

  cards.forEach(function (c) {
    c.addEventListener('click', function () { open(+c.dataset.idx); });
  });

  document.getElementById('lbClose').addEventListener('click', close);
  document.getElementById('lbPrev').addEventListener('click', function () { show(cur - 1); });
  document.getElementById('lbNext').addEventListener('click', function () { show(cur + 1); });
  infoBtn.addEventListener('click', function () { toggleInfo(); });
  img.addEventListener('error', function () { __imgErr(this); });
  img.addEventListener('click', function () {
    if (liveIt) return;                             // 实况照片的点击留给"按住播放"，不做 1:1 缩放
    img.classList.toggle('zoomed');
  });

  /* 实况照片：按住画面播动态（鼠标按下 / 手指按住 / 空格），松手即回到静帧 */
  stage.addEventListener('pointerdown', function (e) {
    if (!liveIt) return;
    if (e.target !== img && e.target !== lvv) return;    // 只按住画面本身才播，点空白处仍旧关灯箱
    e.preventDefault();
    try { stage.setPointerCapture(e.pointerId); } catch (err) {}
    livePlay();
  });
  ['pointerup', 'pointercancel'].forEach(function (ev) {
    stage.addEventListener(ev, function () { if (liveOn) liveReset(); });
  });
  stage.addEventListener('pointerleave', function () { if (liveOn) liveReset(); });

  favB.addEventListener('click', function () {
    if (cur < 0) return;
    var k = ITEMS[cur].x;
    var on = Favs.toggle(k);
    favB.textContent = on ? '♥' : '♡';
    favB.classList.toggle('favd', on);
    document.getElementById('favCount').textContent = Favs.count();
    // 同步卡片状态
    cards.forEach(function (c) { if (c.dataset.key === k) syncFav(c); });
    __toast(on ? '已加入收藏' : '已取消收藏');
  });

  lb.addEventListener('click', function (e) {
    if (e.target === lb || e.target === stage) close();
  });

  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('open')) return;
    // 焦点落在播放器上时，方向键/空格交给浏览器（进度、音量、播放），Esc 仍然关灯箱
    if (e.target && e.target.tagName === 'VIDEO') {
      if (e.key === 'Escape') close();
      return;
    }
    if (e.key === 'Escape')     { close(); }
    else if (e.key === 'ArrowLeft')  { show(cur - 1); }
    else if (e.key === 'ArrowRight') { show(cur + 1); }
    else if (e.key === 'i' || e.key === 'I') { toggleInfo(); }
    else if (e.key === 'f' || e.key === 'F') { favB.click(); }
    else if (e.key === ' ') {
      if (cur >= 0 && ITEMS[cur].k === 'video') {
        e.preventDefault();
        if (vid.paused) vid.play(); else vid.pause();
      } else if (liveIt) {                          // 实况照片的键盘等价操作（免按住）
        e.preventDefault();
        if (liveOn) { liveReset(); if (lvTx) lvTx.textContent = '按住看动态'; }
        else livePlay();
      }
    }
  });

  /* 触屏滑动切换：记录起点，end 时横向位移 >48px 且大于纵向就翻页 */
  var sx = 0, sy = 0;
  lb.addEventListener('touchstart', function (e) {
    sx = e.touches[0].clientX; sy = e.touches[0].clientY;
  }, {passive: true});
  lb.addEventListener('touchend', function (e) {
    var it = ITEMS[cur];
    if (it && it.k === 'video' && !vid.paused) return;    // 播放中不抢滑动（横向拖动可能是拖进度）
    if (liveOn) return;                                   // 按住看实况期间也不抢滑动
    var dx = e.changedTouches[0].clientX - sx;
    var dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dy)) {
      if (dx > 0) show(cur - 1); else show(cur + 1);
    }
  }, {passive: true});
})();

/* ───── 若上次停在时间轴：直接建好分组，省得用户再点一次 ───── */
if (S.view === 'timeline') {
  if (S.sort.indexOf('date') !== 0) {
    S.sort = 'date-desc';
    document.getElementById('sort').value = S.sort;
  }
  applySort();                 // 内部会 refresh 一次
  Timeline.activate();
}

/* ───── 首屏渐进式载入进度提示 ───── */
if (TOTAL === 0) document.getElementById('gl').classList.add('done');

/* ───── 首访鎏光开场：光环遮屏的约 2.4s 里并发拉满、首屏 + 次屏全速预载 ───── */
(function () {
  if (!document.documentElement.classList.contains('splash-on')) return;
  var sp = document.getElementById('splash');
  if (!sp) return;
  var t0 = Date.now();
  var hold = /[?&]splash=hold/.test(location.search);   // ?splash=hold：定格展示（调试/截图用）
  Loader.boost(12);
  // 光环期间用户还不会滚动，趁机把前 30 张全部入队（光环落下即见完整画面）
  cards.slice(0, 30).forEach(function (c) { Loader.enqueue(c, +c.dataset.idx); });
  if (hold) return;
  function finish() {
    var wait = Math.max(0, 2400 - (Date.now() - t0));
    setTimeout(function () {
      sp.classList.add('sp-done');
      document.documentElement.classList.remove('splash-on');
      try { localStorage.setItem('lg_splash', '1'); } catch (e) {}
      setTimeout(function () { if (sp.parentNode) sp.parentNode.removeChild(sp); }, 600);
    }, wait);
  }
  if (document.readyState === 'complete') finish();
  else window.addEventListener('load', finish);
})();
</script>
</body>
</html>
<?php
/* ───── 页面已送达，转入后台预热（仅 FPM） ─────
 * 用户此刻已在看图；当前 PHP 进程用剩余时间生成缩略图缓存，下次访问全部命中。
 * 全量完成后写 warm_done 标志，之后零开销跳过。 */
$html = ob_get_clean();
echo $html;
if (function_exists('fastcgi_finish_request') && php_sapi_name() === 'fpm-fcgi'
    && $total > 0 && !is_file($CONFIG['cache_dir'] . '/warm_done')) {
    fastcgi_finish_request();
    background_warm(12);
}
