<?php
// =================================================================
// ARTFORGE AI v5 — Fichier unique
// =================================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
@mkdir(__DIR__ . '/data', 0775, true);
ini_set('error_log', __DIR__ . '/data/php_errors.log');

session_set_cookie_params(['lifetime'=>30*86400,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
session_start();
set_time_limit(300);

define('DATA_ROOT', __DIR__ . '/data');
define('DB_PATH', DATA_ROOT . '/artforge.sqlite');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
define('AGNES_BASE', 'https://apihub.agnes-ai.com/v1');
define('AGNES_CHAT_URL', AGNES_BASE . '/chat/completions');
define('AGNES_IMAGE_URL', AGNES_BASE . '/images/generations');
define('AGNES_TEXT_MODEL', 'agnes-3.0-flash');
define('AGNES_IMAGE_MODEL', 'agnes-image-2.5-flash');
define('KEYS_COOKIE', 'artforge_keys');

// =================================================================
// DB
// =================================================================
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA journal_mode=WAL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, tiktok_username TEXT UNIQUE NOT NULL, pin TEXT NOT NULL, initial_video_url TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, api_key TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tools (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, slug TEXT NOT NULL, label TEXT NOT NULL, emoji TEXT DEFAULT '✨', category TEXT NOT NULL DEFAULT 'image', prompt TEXT NOT NULL, is_base INTEGER DEFAULT 0, active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, slug))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tool_packs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, description TEXT DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $cols = $pdo->query("PRAGMA table_info(tools)")->fetchAll(PDO::FETCH_ASSOC);
        $hasPack = false; foreach ($cols as $col) if ($col['name'] === 'pack_id') $hasPack = true;
        if (!$hasPack) $pdo->exec("ALTER TABLE tools ADD COLUMN pack_id INTEGER DEFAULT NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tools_user_active ON tools(user_id, active)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, role TEXT NOT NULL, content TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS analyses (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, video_url TEXT, video_title TEXT, thumbnail TEXT, tool_slug TEXT, tool_label TEXT, tool_emoji TEXT, input_text TEXT, result_html TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_analyses_user_date ON analyses(user_id, created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS visual_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, prompt TEXT, image_b64 TEXT, image_url TEXT, mime TEXT DEFAULT 'image/png', source_analysis_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS video_cache (url TEXT PRIMARY KEY, title TEXT, author TEXT, thumbnail TEXT, thumbnails_json TEXT, subtitles TEXT, created_at INTEGER)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS threads (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, context_type TEXT NOT NULL, context_id INTEGER NOT NULL DEFAULT 0, title TEXT, context_content TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, context_type, context_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS thread_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL, role TEXT NOT NULL, content TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    }
    return $pdo;
}

// =================================================================
// OUTILS DE BASE
// =================================================================
function baseTools() {
    $h = " Réponds UNIQUEMENT en HTML valide, sans markdown (** # ``` --- tableaux interdits). Utilise <p>, <ul>, <li>, <strong>, <h3>.";
    return [
        ['face','👤','Analyse du visage','image',"Analyse en détail le visage : âge estimé, genre, expression, traits, accessoires, potentiel esthétique/marketing. Style coach TikTok.".$h],
        ['movie','🎬','Style cinéma','image',"Cette image ressemble-t-elle à une scène de film ? Quel film, genre, ambiance ? Sinon décris son style visuel.".$h],
        ['sales','🛍️','Quoi lui vendre','image',"Analyse marketing : quels produits ou services pourraient être vendus avec ce visuel ? 3 offres concrètes.".$h],
        ['img2txt','📝','Description image','image',"Description complète : sujet, arrière-plan, couleurs, composition, éclairage, ambiance.".$h],
        ['art_critique','🎨',"Critique d'art",'image',"Si c'est une œuvre d'art, critique professionnelle : mouvement, technique, symbolismes, émotion.".$h],
        ['story','📖','Inventer une histoire','image',"Invente une histoire cinématographique (300 mots) : personnage, contexte, intrigue, chute.".$h],
        ['monetization_img','💰','Monétisation image','image',"Potentiel de monétisation : niches, partenariats, produits. Note sur 10 + 3 actions.".$h],
        ['actor','🎭','Potentiel acteur','image',"Évalue le potentiel acteur/actrice : présence, charisme, types de rôles.".$h],
        ['topmodel','💃','Potentiel top model','image',"Évalue le potentiel mannequin : proportions, style, photogénie, marques.".$h],
        ['suggestions_img','💡','Suggestions','image',"5 suggestions actionnables pour améliorer ce contenu visuel.".$h],
        ['legal','⚖️','Aspect légal','text',"Analyse l'aspect légal : droits d'auteur, mentions, conformité publicité, RGPD.".$h],
        ['improvement','🚀','Comment améliorer','text',"Comment améliorer : clarté, engagement, CTA, storytelling. Avant/après.".$h],
        ['translation','🌍','Traduction FR→EN→ES','text',"Traduis en anglais puis espagnol, ton marketing.".$h],
        ['monetization_text','💸','Monétisation texte','text',"Analyse la monétisation : niches, offres, conversion. Note sur 10.".$h],
        ['creative','🎭','Création artistique','text',"Idées de création artistique à partir de ce texte.".$h],
        ['optimization','⚡','Optimisation TikTok','text',"Optimise pour TikTok : hook, rythme, SEO, hashtags, CTA.".$h],
        ['hooks','🪝','Hooks & captions','text',"10 hooks accrocheurs + 5 captions alternatives.".$h],
        ['hashtags','#️⃣','Hashtags stratégiques','text',"20 hashtags stratégiques classés.".$h],
        ['engagement','❤️','Boost engagement','text',"Comment maximiser l'engagement : questions, CTA, formats.".$h],
        ['script_rewrite','✍️','Réécriture script','text',"Réécris ce script en version TikTok (30s) : hook, tension, payoff, CTA.".$h],
        ['coherence','🎯','Cohérence image/texte','combined',"Compare l'image et le texte : cohérence ? Score sur 10 + 3 améliorations.".$h],
        ['alt_text','🔍','Alt text SEO','combined',"Alt text SEO optimisé basé sur l'image ET le texte.".$h],
        ['caption_suggest','✏️','Caption suggérée','combined',"3 captions TikTok percutantes.".$h],
        ['match_score','📊','Score viral','combined',"Score viral sur 100, décomposition (hook, visuel, message, CTA).".$h],
        ['visual_pack','🎨','Pack visuel','visual',"Génère une image promotionnelle TikTok vif, composition verticale, couleurs contrastées, style pop."],
        ['visual_thumb','🖼️','Miniature alternative','visual',"Génère une miniature alternative accrocheuse : contraste élevé, visage expressif, texte lisible."],
        ['visual_brand','🏷️','Identité de marque','visual',"Génère un visuel d'identité : logo abstrait, palette, ambiance. Minimaliste et moderne."],
    ];
}

function ensureBaseTools($userId) {
    // Le catalogue historique est explicitement regroupé dans un pack système.
    $q=db()->prepare("SELECT id FROM tool_packs WHERE user_id=? AND name=? LIMIT 1");
    $q->execute([$userId,'Pack de base — ARTFORGE']); $packId=(int)$q->fetchColumn();
    if(!$packId){db()->prepare("INSERT INTO tool_packs(user_id,name,description) VALUES(?,?,?)")->execute([$userId,'Pack de base — ARTFORGE','Catalogue historique livré avec ARTFORGE. Les outils restent modifiables mais le pack système ne peut pas être supprimé.']);$packId=(int)db()->lastInsertId();}
    // Synchronisation additive : complète la base sans réactiver les outils désactivés.
    $ins = db()->prepare("INSERT OR IGNORE INTO tools (user_id, slug, label, emoji, category, prompt, is_base, active, pack_id) VALUES (?,?,?,?,?,?,1,1,?)");
    foreach (baseTools() as $t) $ins->execute([$userId, $t[0], $t[2], $t[1], $t[3], $t[4],$packId]);
    db()->prepare("UPDATE tools SET pack_id=? WHERE user_id=? AND is_base=1 AND pack_id IS NULL")->execute([$packId,$userId]);
}

function getUserTools($userId, $activeOnly = false) {
    $sql = "SELECT * FROM tools WHERE user_id = ?" . ($activeOnly ? " AND active = 1" : "") . " ORDER BY is_base DESC, category, id";
    $stmt = db()->prepare($sql); $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =================================================================
// TIKTOK
// =================================================================
function fetchHtml($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>USER_AGENT, CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,*/*;q=0.8','Accept-Language: fr-FR,fr;q=0.9']]);
    $html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($code === 200 && $html !== false) ? $html : null;
}
function extractUniversalData($html) {
    if (preg_match('/<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__" type="application\/json">(.*?)<\/script>/s', $html, $m)) return json_decode($m[1], true);
    return null;
}
function extractProfileStats($json) {
    $ud = $json['__DEFAULT_SCOPE__']['webapp.user-detail'] ?? []; $ui = $ud['userInfo'] ?? []; $s = $ui['statsV2'] ?? [];
    return ['nickname'=>$ui['user']['nickname']??'','uniqueId'=>$ui['user']['uniqueId']??'','bio'=>$ui['user']['signature']??'','avatar'=>$ui['user']['avatarLarger']??'','followers'=>(int)($s['followerCount']??0),'following'=>(int)($s['followingCount']??0),'likes'=>(int)($s['heartCount']??0),'videoCount'=>(int)($s['videoCount']??0)];
}
function extractSubtitles($json) {
    $subs = $json['__DEFAULT_SCOPE__']['webapp.video-detail']['itemInfo']['itemStruct']['video']['subtitleInfos'] ?? null;
    if (empty($subs)) return null;
    $vttUrl = null; foreach ($subs as $s) if (!empty($s['Url'])) { $vttUrl = $s['Url']; break; }
    if (!$vttUrl) return null;
    $vtt = fetchHtml($vttUrl); if (!$vtt) return null;
    $t = preg_replace('/^WEBVTT.*?\n\n/s', '', $vtt);
    $t = preg_replace('/\d{2}:\d{2}:\d{2}\.\d{3} --> \d{2}:\d{2}:\d{2}\.\d{3}.*?\n/', '', $t);
    $t = strip_tags($t); $t = preg_replace("/\n{2,}/", "\n", $t);
    return trim($t) ?: null;
}
function resolveTikTokUrl($url) {
    if (preg_match('#(vm|vt)\.tiktok\.com#', $url)) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>USER_AGENT, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false]);
        curl_exec($ch); $eff = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); curl_close($ch);
        if ($eff) return $eff;
    }
    return $url;
}
function extractUsernameFromUrl($url) { if (preg_match('/@([a-zA-Z0-9._]+)/', $url, $m)) return $m[1]; return null; }
function fetchProfileInfo($username) {
    $html = fetchHtml("https://www.tiktok.com/@" . $username);
    if (!$html) return null; $json = extractUniversalData($html);
    return $json ? extractProfileStats($json) : null;
}
function getVideoDataCached($url) {
    $url = resolveTikTokUrl($url);
    $stmt = db()->prepare("SELECT * FROM video_cache WHERE url = ? AND created_at > ?"); $stmt->execute([$url, time()-3600]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cached) return ['url'=>$url,'title'=>$cached['title'],'author'=>$cached['author'],'thumbnail'=>$cached['thumbnail'],'thumbnails'=>json_decode($cached['thumbnails_json'] ?: '[]', true),'subtitles'=>$cached['subtitles']];
    $data = ['url'=>$url,'thumbnails'=>[],'thumbnail'=>null,'title'=>null,'author'=>null,'subtitles'=>null];
    $html = fetchHtml($url);
    if ($html) {
        $json = extractUniversalData($html);
        if ($json) {
            $data['subtitles'] = extractSubtitles($json);
            $item = $json['__DEFAULT_SCOPE__']['webapp.video-detail']['itemInfo']['itemStruct'] ?? null;
            if ($item) {
                $data['title'] = $item['desc'] ?? null; $data['author'] = $item['author']['uniqueId'] ?? null;
                $v = $item['video'] ?? [];
                foreach (['originCover','cover','dynamicCover'] as $k) if (!empty($v[$k]) && filter_var($v[$k], FILTER_VALIDATE_URL)) $data['thumbnails'][] = $v[$k];
            }
        }
    }
    $ch = curl_init("https://www.tiktok.com/oembed?url=" . urlencode($url));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_USERAGENT=>USER_AGENT, CURLOPT_SSL_VERIFYPEER=>false]);
    $oe = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code === 200 && $oe) { $j = json_decode($oe, true); if (!empty($j['thumbnail_url'])) $data['thumbnails'][] = $j['thumbnail_url']; if (!$data['title']) $data['title'] = $j['title'] ?? null; if (!$data['author']) $data['author'] = $j['author_name'] ?? null; }
    $data['thumbnails'] = array_values(array_unique($data['thumbnails'])); $data['thumbnail'] = $data['thumbnails'][0] ?? null;
    db()->prepare("INSERT OR REPLACE INTO video_cache (url, title, author, thumbnail, thumbnails_json, subtitles, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$url, $data['title'], $data['author'], $data['thumbnail'], json_encode($data['thumbnails']), $data['subtitles'], time()]);
    return $data;
}
function imageToBase64($url, $max = 1024) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>25, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_USERAGENT=>USER_AGENT, CURLOPT_HTTPHEADER=>['Referer: https://www.tiktok.com/','Origin: https://www.tiktok.com','Accept: image/*,*/*;q=0.8']]);
    $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg'; curl_close($ch);
    if ($code !== 200 || !$data) return null;
    if (strpos($mime, 'image/') !== 0) $mime = 'image/jpeg';
    if (function_exists('imagecreatefromstring') && strlen($data) > 400000) {
        $img = @imagecreatefromstring($data);
        if ($img) { $w = imagesx($img); $h = imagesy($img); $scale = min($max/max($w,1), $max/max($h,1), 1);
            if ($scale < 1) { $nw = max(1,(int)($w*$scale)); $nh = max(1,(int)($h*$scale)); $new = imagecreatetruecolor($nw, $nh); imagecopyresampled($new, $img, 0,0,0,0, $nw,$nh, $w,$h); ob_start(); imagejpeg($new, null, 85); $data = ob_get_clean(); $mime = 'image/jpeg'; imagedestroy($new); }
            imagedestroy($img); }
    }
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

// =================================================================
// IA
// =================================================================
function aiCallOnce($apiKey, $messages, $maxTokens = 2000) {
    $ch = curl_init(AGNES_CHAT_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['model'=>AGNES_TEXT_MODEL,'messages'=>$messages,'max_tokens'=>$maxTokens,'temperature'=>0.7]), CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey], CURLOPT_TIMEOUT=>90, CURLOPT_SSL_VERIFYPEER=>true]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $r = json_decode($raw, true);
    if (isset($r['choices'][0]['message']['content'])) return ['ok'=>true, 'text'=>$r['choices'][0]['message']['content']];
    return ['ok'=>false, 'error'=>$r['error']['message'] ?? $r['detail'] ?? ('HTTP '.$code)];
}
function generateImage($apiKey, $prompt, $size = '1024x1024') {
    $ch = curl_init(AGNES_IMAGE_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['model'=>AGNES_IMAGE_MODEL,'prompt'=>$prompt,'size'=>$size,'n'=>1,'response_format'=>'b64_json']), CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey], CURLOPT_TIMEOUT=>180, CURLOPT_SSL_VERIFYPEER=>true]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $r = json_decode($raw, true);
    if (isset($r['data'][0]['b64_json'])) return ['ok'=>true, 'b64'=>$r['data'][0]['b64_json'], 'mime'=>'image/png'];
    if (isset($r['data'][0]['url'])) return ['ok'=>true, 'url'=>$r['data'][0]['url'], 'mime'=>'image/png'];
    return ['ok'=>false, 'error'=>$r['error']['message'] ?? $r['detail'] ?? ('HTTP '.$code)];
}
function getFirstKey($userId) { $keys = getUserKeys($userId); return $keys[0] ?? null; }
function getUserKeys($userId) {
    $stmt = db()->prepare("SELECT api_key FROM api_keys WHERE user_id = ? ORDER BY id");
    $stmt->execute([$userId]); return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
function aiCallWithKeys($userId, $messages, $maxTokens = 2000, $start = 0) {
    $keys=getUserKeys($userId); if(!$keys) return ['ok'=>false,'error'=>'Aucune clé API configurée'];
    $errors=[]; $n=count($keys);
    for($i=0;$i<$n;$i++){ $idx=($start+$i)%$n; $r=aiCallOnce($keys[$idx],$messages,$maxTokens); if($r['ok']) return $r; $errors[]='Clé '.($idx+1).' : '.$r['error']; }
    return ['ok'=>false,'error'=>implode(' | ',array_slice($errors,0,3))];
}
function validToolCategory($category) { return in_array($category,['image','text','combined','visual'],true); }
function generateImageWithKeys($userId,$prompt,$size='1024x1024',$start=0) {
    $keys=getUserKeys($userId); if(!$keys)return ['ok'=>false,'error'=>'Aucune clé API configurée']; $errors=[];
    for($i=0;$i<count($keys);$i++){ $idx=($start+$i)%count($keys); $r=generateImage($keys[$idx],$prompt,$size); if($r['ok'])return $r; $errors[]='Clé '.($idx+1).' : '.$r['error']; }
    return ['ok'=>false,'error'=>implode(' | ',array_slice($errors,0,3))];
}
function saveGeneratedAsset($userId,$prompt,$res) {
    db()->prepare("INSERT INTO visual_assets(user_id,prompt,image_b64,image_url,mime) VALUES(?,?,?,?,?)")->execute([$userId,$prompt,$res['b64']??null,$res['url']??null,$res['mime']??'image/png']);
    return (int)db()->lastInsertId();
}
function parseAiJson($raw) {
    $raw=trim((string)$raw); $raw=preg_replace('/^```(?:json)?\s*/i','',$raw); $raw=preg_replace('/\s*```$/','',$raw);
    foreach([['{','}'],['[',']']] as $pair){$a=strpos($raw,$pair[0]);$b=strrpos($raw,$pair[1]);if($a!==false&&$b!==false&&$b>=$a){$j=json_decode(substr($raw,$a,$b-$a+1),true);if(is_array($j))return $j;}}
    return null;
}

// =================================================================
// FORMAT RESPONSE
// =================================================================
function formatAiResponse($text) {
    if (!is_string($text) || trim($text) === '') return '<p><em>(Pas de réponse)</em></p>';
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    // Nettoyer TOUS les blocs tool/image (backticks ET chevrons)
    $text = preg_replace('/```(?:create_tool|generate_image)[\s\S]*?```/s', '', $text);
    $text = preg_replace('/<(?:create_tool|generate_image)>[\s\S]*?<\/(?:create_tool|generate_image)>/s', '', $text);
    $text = preg_replace('/```[a-zA-Z]*\n?[\s\S]*?```/', '', $text);
    $text = preg_replace('/~~~[\s\S]*?~~~/', '', $text);
    $text = trim($text);
    if ($text === '') return '<p><em>(Pas de réponse)</em></p>';
    // Si HTML, garde tel quel (après sanitize)
    if (preg_match('/<(p|div|ul|ol|h[1-6]|br|li|strong|em|a|span|blockquote|img)\b[^>]*>/i', $text)) {
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text);
        $text = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $text);
        $text = preg_replace('/\son\w+\s*=\s*["\'][^"\']*["\']/i', '', $text);
        return $text;
    }
    // Markdown → HTML
    $html = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/^######\s+(.+)$/m', '<h6>$1</h6>', $html);
    $html = preg_replace('/^#####\s+(.+)$/m', '<h5>$1</h5>', $html);
    $html = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $html);
    $html = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $html);
    $html = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/__([^_\n]+)__/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $html);
    $html = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $html);
    $lines = explode("\n", $html); $out = []; $inList = false;
    foreach ($lines as $line) {
        $isLi = preg_match('/^\s*(?:[-*+]|\d+\.)\s+.+$/', $line);
        if ($isLi) { if (!$inList) { $out[] = '<ul>'; $inList = true; } $content = preg_replace('/^\s*(?:[-*+]|\d+\.)\s+/', '', $line); $out[] = '<li>'.$content.'</li>'; }
        else { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = $line; }
    }
    if ($inList) $out[] = '</ul>';
    $html = implode("\n", $out);
    $blocks = preg_split('/\n{2,}/', trim($html)); $final = [];
    foreach ($blocks as $b) { $b = trim($b); if ($b === '') continue;
        if (preg_match('/^<(h[1-6]|ul|ol|p|div|blockquote|pre)/i', $b)) $final[] = $b;
        else $final[] = '<p>' . preg_replace('/\n/', '<br>', $b) . '</p>';
    }
    return implode("\n", $final) ?: '<p><em>(Pas de réponse)</em></p>';
}

// =================================================================
// PROMPTS SYSTÈME
// =================================================================
function buildChatSystemPrompt($tools) {
    $list = ''; foreach ($tools as $t) $list .= "- [{$t['category']}] {$t['emoji']} {$t['label']} : ".mb_substr($t['prompt'],0,100)."...\n";
    return "Tu es ARTFORGE AI 🤖, assistant TikTok expert.\n\nOutils actuels :\n{$list}\n\n"
        . "FORMAT OBLIGATOIRE POUR CRÉER UN OUTIL (utilise EXACTEMENT ce format avec les backticks) :\n"
        . "```create_tool\n"
        . "{\"slug\":\"snake_case\",\"label\":\"Nom\",\"emoji\":\"🔥\",\"category\":\"image|text|combined|visual\",\"prompt\":\"...\"}\n"
        . "```\n\n"
        . "FORMAT OBLIGATOIRE POUR GÉNÉRER UNE IMAGE :\n"
        . "```generate_image\n"
        . "{\"prompt\":\"description détaillée...\"}\n"
        . "```\n\n"
        . "CATEGORIES :\n"
        . "- image : analyse une miniature (image seule en entrée)\n"
        . "- text : analyse du texte seul\n"
        . "- combined : analyse image + texte ensemble\n"
        . "- visual : génère une IMAGE (peut utiliser le texte d'entrée comme inspiration, tu peux demander des transformations comme traduction/réécriture dans le prompt, elles seront exécutées avant la génération)\n\n"
        . "RÈGLES :\n"
        . "1. Réponds en HTML valide (p, ul, li, strong, h3). INTERDIT : **, #, ---, tableaux markdown.\n"
        . "2. Français, concis (3-4 phrases avant un éventuel bloc).\n"
        . "3. N'utilise JAMAIS <create_tool>...</create_tool> ni aucune autre syntaxe. UNIQUEMENT les backticks.\n"
        . "4. Pas de bloc si l'utilisateur demande juste une info.\n"
        . "5. Pour un outil qui fait PLUSIEURS choses (ex: traduire PUIS générer une image), utilise category=visual avec un prompt qui décrit toute la transformation.\n"
        . "6. Sois créatif : monétisation, hooks, branding, storytelling, tendances TikTok.";
}

// =================================================================
// EXTRACTION (accepte backticks ET chevrons ET autres)
// =================================================================
function extractToolFromResponse($text) {
    // 1. Format backticks classique
    if (preg_match('/```create_tool\s*(\{.*?\})\s*```/s', $text, $m)) {
        $j = json_decode($m[1], true);
        if (validToolJson($j)) return $j;
    }
    // 2. Format chevrons <create_tool>...</create_tool>
    if (preg_match('/<create_tool>\s*(\{.*?\})\s*<\/create_tool>/s', $text, $m)) {
        $j = json_decode($m[1], true);
        if (validToolJson($j)) return $j;
    }
    // 3. Fallback : chercher un JSON avec les clés attendues n'importe où
    if (preg_match('/\{[^{}]*"slug"[^{}]*"label"[^{}]*"category"[^{}]*"prompt"[^{}]*\}/s', $text, $m)) {
        $j = json_decode($m[0], true);
        if (validToolJson($j)) return $j;
    }
    if (preg_match('/\{[^{}]*"label"[^{}]*"category"[^{}]*"prompt"[^{}]*\}/s', $text, $m)) {
        $j = json_decode($m[0], true);
        if (validToolJson($j)) return $j;
    }
    return null;
}
function validToolJson($j) {
    return $j && isset($j['label'],$j['prompt'],$j['category'])
        && in_array($j['category'], ['image','text','combined','visual'])
        && mb_strlen($j['prompt']) > 20;
}
function extractImageRequest($text) {
    if (preg_match('/```generate_image\s*(\{.*?\})\s*```/s', $text, $m)) { $j = json_decode($m[1], true); if ($j && !empty($j['prompt'])) return $j; }
    if (preg_match('/<generate_image>\s*(\{.*?\})\s*<\/generate_image>/s', $text, $m)) { $j = json_decode($m[1], true); if ($j && !empty($j['prompt'])) return $j; }
    return null;
}
function createCustomTool($userId, $tool) {
    $slug = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $tool['slug'] ?? 'tool')); $slug = trim($slug, '_') ?: 'tool';
    $base = $slug; $n = 1;
    while (true) { $chk = db()->prepare("SELECT id FROM tools WHERE user_id = ? AND slug = ?"); $chk->execute([$userId, $slug]); if (!$chk->fetch()) break; $slug = $base.'_'.(++$n); }
    $ins = db()->prepare("INSERT INTO tools (user_id, slug, label, emoji, category, prompt, is_base, pack_id) VALUES (?,?,?,?,?,?,0,?)");
    $ins->execute([$userId, $slug, mb_substr($tool['label'],0,80), $tool['emoji'] ?? '✨', $tool['category'], $tool['prompt'], $tool['pack_id'] ?? null]);
    return ['id'=>db()->lastInsertId(), 'slug'=>$slug];
}

// =================================================================
// COOKIE
// =================================================================
function saveKeysCookie($keys) { @setcookie(KEYS_COOKIE, base64_encode(json_encode($keys)), ['expires'=>time()+365*86400,'path'=>'/','samesite'=>'Lax']); }
function loadKeysCookie() { if (!empty($_COOKIE[KEYS_COOKIE])) { $d = json_decode(base64_decode($_COOKIE[KEYS_COOKIE]), true); return is_array($d) ? $d : []; } return []; }

// =================================================================
// JSON OUT
// =================================================================
function jsonOut($data) {
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) $json = '{"success":false,"error":"JSON encode error"}';
    echo $json; exit;
}

// =================================================================
// API
// =================================================================
$action = $_GET['action'] ?? null;
if ($action) {
    @ob_start();
    $input = json_decode(@file_get_contents('php://input'), true) ?: [];
    try {
        if ($action === 'register') {
            $url = trim($input['url'] ?? ''); $pin = trim($input['pin'] ?? '');
            if (!$url || !preg_match('/^\d{4}$/', $pin)) jsonOut(['success'=>false,'error'=>'URL et PIN 4 chiffres requis']);
            $long = resolveTikTokUrl($url); $username = extractUsernameFromUrl($long);
            if (!$username) jsonOut(['success'=>false,'error'=>'Nom TikTok introuvable']);
            $chk = db()->prepare("SELECT id FROM users WHERE tiktok_username = ?"); $chk->execute([$username]);
            if ($chk->fetch()) jsonOut(['success'=>false,'error'=>"@$username existe déjà"]);
            $isVideo = (strpos($long, '/video/') !== false);
            db()->prepare("INSERT INTO users (tiktok_username, pin, initial_video_url) VALUES (?,?,?)")->execute([$username, password_hash($pin, PASSWORD_DEFAULT), $isVideo ? $long : null]);
            $newId = db()->lastInsertId(); ensureBaseTools($newId);
            $_SESSION['user_id'] = $newId; $_SESSION['username'] = $username;
            @setcookie('artforge_last_user', $username, ['expires'=>time()+365*86400, 'path'=>'/', 'samesite'=>'Lax']);
            jsonOut(['success'=>true,'username'=>$username]);
        }
        if ($action === 'login') {
            $username = ltrim(trim($input['username'] ?? ''), '@'); $pin = trim($input['pin'] ?? '');
            if (!$username || !preg_match('/^\d{4}$/', $pin)) jsonOut(['success'=>false,'error'=>'Identifiant + PIN requis']);
            usleep(300000);
            $stmt = db()->prepare("SELECT * FROM users WHERE tiktok_username = ?"); $stmt->execute([$username]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u || !password_verify($pin, $u['pin'])) jsonOut(['success'=>false,'error'=>'Identifiants incorrects']);
            ensureBaseTools($u['id']);
            $_SESSION['user_id'] = $u['id']; $_SESSION['username'] = $u['tiktok_username'];
            @setcookie('artforge_last_user', $u['tiktok_username'], ['expires'=>time()+365*86400, 'path'=>'/', 'samesite'=>'Lax']);
            $stmt = db()->prepare("SELECT COUNT(*) FROM api_keys WHERE user_id = ?"); $stmt->execute([$u['id']]);
            if ((int)$stmt->fetchColumn() === 0) {
                $ck = loadKeysCookie();
                if (!empty($ck)) { $ins = db()->prepare("INSERT INTO api_keys (user_id, api_key) VALUES (?,?)"); foreach ($ck as $k) $ins->execute([$u['id'], $k]); }
            }
            jsonOut(['success'=>true]);
        }
        if ($action === 'logout') { session_destroy(); jsonOut(['success'=>true]); }
        if (!isset($_SESSION['user_id'])) jsonOut(['success'=>false,'error'=>'Non connecté']);
        $userId = $_SESSION['user_id'];

        if ($action === 'save_keys') {
            $keys = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $input['keys'] ?? '')))));
            db()->prepare("DELETE FROM api_keys WHERE user_id = ?")->execute([$userId]);
            $ins = db()->prepare("INSERT INTO api_keys (user_id, api_key) VALUES (?,?)");
            foreach ($keys as $k) $ins->execute([$userId, $k]);
            saveKeysCookie($keys);
            jsonOut(['success'=>true,'count'=>count($keys)]);
        }
        if ($action === 'get_subtitles') {
            $url = trim($input['url'] ?? ''); if (!$url) jsonOut(['success'=>false,'error'=>'URL requise']);
            $vd = getVideoDataCached($url);
            jsonOut(['success'=>true,'subtitles'=>$vd['subtitles'],'thumbnail'=>$vd['thumbnail'],'title'=>$vd['title'],'author'=>$vd['author'],'long_url'=>$vd['url']]);
        }

        // ---- ANALYZE ONE (inclut prep LLM pour visual) ----
        if ($action === 'analyze_one') {
            $url = trim($input['url'] ?? ''); $text = trim($input['text'] ?? ''); $slug = trim($input['tool'] ?? ''); $keyIdx = max(0,(int)($input['key_index'] ?? 0));
            if (!$slug) jsonOut(['success'=>false,'error'=>'Aucun outil']);
            $stmt = db()->prepare("SELECT api_key FROM api_keys WHERE user_id = ? ORDER BY id"); $stmt->execute([$userId]);
            $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($keys)) jsonOut(['success'=>false,'error'=>'Configure une clé API']);
            $key = $keys[$keyIdx % count($keys)];
            $stmt = db()->prepare("SELECT * FROM tools WHERE user_id = ? AND slug = ? AND active = 1"); $stmt->execute([$userId, $slug]);
            $tool = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tool) jsonOut(['success'=>false,'error'=>'Outil introuvable']);

            $imageBase64 = null; $thumbnailUrl = null; $videoTitle = null;
            if ($url) {
                $vd = getVideoDataCached($url);
                $thumbnailUrl = $vd['thumbnail']; $videoTitle = $vd['title'];
                if (!$text && !empty($vd['subtitles'])) $text = $vd['subtitles'];
                if (in_array($tool['category'], ['image','combined'])) {
                    foreach ($vd['thumbnails'] as $tu) { $imageBase64 = imageToBase64($tu); if ($imageBase64) break; }
                    if (!$imageBase64) jsonOut(['success'=>false,'error'=>'Miniature introuvable']);
                }
            }
            if ($tool['category'] === 'text' && empty($text)) jsonOut(['success'=>false,'error'=>'Texte requis']);
            if ($tool['category'] === 'combined' && (empty($text) || empty($imageBase64))) jsonOut(['success'=>false,'error'=>'Image ET texte requis']);

            $msgs = [];
            if ($tool['category'] === 'image' && $imageBase64) $msgs[] = ['role'=>'user','content'=>[['type'=>'text','text'=>$tool['prompt']],['type'=>'image_url','image_url'=>['url'=>$imageBase64]]]];
            elseif ($tool['category'] === 'text') $msgs[] = ['role'=>'user','content'=>$tool['prompt']."\n\n---\nTEXTE :\n".$text];
            elseif ($tool['category'] === 'combined' && $imageBase64) $msgs[] = ['role'=>'user','content'=>[['type'=>'text','text'=>$tool['prompt']."\n\n---\nTEXTE :\n".$text],['type'=>'image_url','image_url'=>['url'=>$imageBase64]]]];
            elseif ($tool['category'] === 'visual') {
                // ÉTAPE 1 : LLM prépare la description d'image
                $prepPrompt = "Tu es un expert prompt pour générateurs d'images IA (DALL-E, Midjourney, Flux).\n"
                    . "Transforme la demande suivante en une DESCRIPTION VISUELLE DÉTAILLÉE (200 mots max) optimale pour un générateur d'images.\n"
                    . "Inclus : sujet, style, couleurs, composition, ambiance, éléments culturels spécifiques, et tout texte qui doit apparaître dans l'image.\n"
                    . "Si la demande implique une transformation de texte (traduction, réécriture, adaptation), EFFECTUE-LA et intègre le résultat exact dans ta description (entre guillemets pour indiquer le texte à afficher).\n"
                    . "Réponds UNIQUEMENT par la description finale, sans markdown, sans JSON, sans préambule, sans commentaire.\n\n"
                    . "DEMANDE :\n" . $tool['prompt'];
                if ($text) $prepPrompt .= "\n\nTEXTE ASSOCIÉ À TRANSFORMER :\n" . $text;
                $prepRes = aiCallWithKeys($userId, [['role'=>'user','content'=>$prepPrompt]], 1200, $keyIdx);
                $imageDesc = $prepRes['ok'] ? trim($prepRes['text']) : $tool['prompt'];
                // ÉTAPE 2 : génération
                $res = generateImageWithKeys($userId, $imageDesc, '1024x1024', $keyIdx);
                if (!$res['ok']) jsonOut(['success'=>false,'error'=>'Génération : '.$res['error']]);
                $img = $res['b64'] ? 'data:'.$res['mime'].';base64,'.$res['b64'] : $res['url'];
                db()->prepare("INSERT INTO visual_assets (user_id, prompt, image_b64, image_url, mime) VALUES (?,?,?,?,?)")->execute([$userId, $imageDesc, $res['b64'] ?? null, $res['url'] ?? null, $res['mime'] ?? 'image/png']);
                $assetId = db()->lastInsertId();
                $html = '<h3>Description générée</h3><p>' . nl2br(htmlspecialchars($imageDesc)) . '</p><h3>Image</h3><p><img src="'.$img.'" alt="image générée"></p>';
                db()->prepare("INSERT INTO analyses (user_id, video_url, video_title, thumbnail, tool_slug, tool_label, tool_emoji, input_text, result_html) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$userId, $url ?: null, $videoTitle, $thumbnailUrl, $tool['slug'], $tool['label'], $tool['emoji'], $text ?: null, $html]);
                $analysisId = db()->lastInsertId();
                jsonOut(['success'=>true,'slug'=>$tool['slug'],'label'=>$tool['label'],'emoji'=>$tool['emoji'],'html'=>$html,'thumbnail'=>$thumbnailUrl,'video_title'=>$videoTitle,'analysis_id'=>$analysisId,'asset_id'=>$assetId,'image'=>$img]);
            } else $msgs[] = ['role'=>'user','content'=>$tool['prompt']];

            $res = aiCallWithKeys($userId, $msgs, 2000, $keyIdx);
            if (!$res['ok']) jsonOut(['success'=>false,'error'=>$res['error'],'slug'=>$tool['slug']]);
            $html = formatAiResponse($res['text']);
            db()->prepare("INSERT INTO analyses (user_id, video_url, video_title, thumbnail, tool_slug, tool_label, tool_emoji, input_text, result_html) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$userId, $url ?: null, $videoTitle, $thumbnailUrl, $tool['slug'], $tool['label'], $tool['emoji'], $text ?: null, $html]);
            $analysisId = db()->lastInsertId();
            jsonOut(['success'=>true,'slug'=>$tool['slug'],'label'=>$tool['label'],'emoji'=>$tool['emoji'],'html'=>$html,'thumbnail'=>$thumbnailUrl,'video_title'=>$videoTitle,'analysis_id'=>$analysisId]);
        }

        // ---- CHAT PRINCIPAL (fix parsing multi-format) ----
        if ($action === 'chat') {
            $msg = trim($input['message'] ?? ''); if (!$msg) jsonOut(['success'=>false,'error'=>'Message vide']);
            $key = getFirstKey($userId); if (!$key) jsonOut(['success'=>false,'error'=>'Configure une clé API']);
            $hist = db()->prepare("SELECT role, content FROM chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 8"); $hist->execute([$userId]);
            $history = array_reverse($hist->fetchAll(PDO::FETCH_ASSOC));
            db()->prepare("INSERT INTO chat_messages (user_id, role, content) VALUES (?,?,?)")->execute([$userId, 'user', $msg]);
            $tools = getUserTools($userId);
            $messages = [['role'=>'system', 'content'=>buildChatSystemPrompt($tools)]];
            foreach ($history as $h) $messages[] = ['role'=>$h['role'], 'content'=>$h['content']];
            $messages[] = ['role'=>'user', 'content'=>$msg];
            $res = aiCallWithKeys($userId, $messages, 2000);
            if (!$res['ok']) jsonOut(['success'=>false, 'error'=>$res['error']]);
            $aiText = $res['text']; $createdTool = null; $generatedImage = null;

            $toolJson = extractToolFromResponse($aiText);
            if ($toolJson) {
                $info = createCustomTool($userId, $toolJson);
                $createdTool = array_merge($toolJson, ['slug'=>$info['slug'], 'id'=>$info['id']]);
                // Nettoyer le bloc du texte affiché
                $aiText = preg_replace('/```create_tool\s*\{.*?\}\s*```/s', '', $aiText);
                $aiText = preg_replace('/<create_tool>\s*\{.*?\}\s*<\/create_tool>/s', '', $aiText);
            }
            $imageReq = extractImageRequest($aiText);
            if ($imageReq) {
                $imgRes = generateImage($key, $imageReq['prompt']);
                if ($imgRes['ok']) {
                    $img = $imgRes['b64'] ? 'data:'.$imgRes['mime'].';base64,'.$imgRes['b64'] : $imgRes['url'];
                    db()->prepare("INSERT INTO visual_assets (user_id, prompt, image_b64, image_url, mime) VALUES (?,?,?,?,?)")->execute([$userId, $imageReq['prompt'], $imgRes['b64'] ?? null, $imgRes['url'] ?? null, $imgRes['mime'] ?? 'image/png']);
                    $generatedImage = $img;
                } else $aiText .= "\n<p><em>(Image échouée : ".htmlspecialchars($imgRes['error']).")</em></p>";
                $aiText = preg_replace('/```generate_image\s*\{.*?\}\s*```/s', '', $aiText);
                $aiText = preg_replace('/<generate_image>\s*\{.*?\}\s*<\/generate_image>/s', '', $aiText);
            }
            $cleanHtml = formatAiResponse($aiText);
            db()->prepare("INSERT INTO chat_messages (user_id, role, content) VALUES (?,?,?)")->execute([$userId, 'assistant', $aiText]);
            jsonOut(['success'=>true,'reply'=>$cleanHtml,'created_tool'=>$createdTool,'generated_image'=>$generatedImage]);
        }

        // ---- IMPROVE PROMPT ----
        if ($action === 'improve_prompt') {
            $toolId = (int)($input['tool_id'] ?? 0); if (!$toolId) jsonOut(['success'=>false,'error'=>'tool_id requis']);
            $stmt = db()->prepare("SELECT * FROM tools WHERE id = ? AND user_id = ?"); $stmt->execute([$toolId, $userId]);
            $tool = $stmt->fetch(PDO::FETCH_ASSOC); if (!$tool) jsonOut(['success'=>false,'error'=>'Outil introuvable']);
            $key = getFirstKey($userId); if (!$key) jsonOut(['success'=>false,'error'=>'Configure une clé API']);
            $prompt = "Expert prompt engineering TikTok. Améliore ce prompt d'outil d'analyse/génération pour qu'il soit plus précis, professionnel, actionnable.\n\n"
                . "Catégorie : {$tool['category']}\nLabel : {$tool['label']}\nPrompt actuel :\n{$tool['prompt']}\n\n"
                . "Retourne UNIQUEMENT le nouveau prompt amélioré, sans markdown, sans guillemets, sans bloc de code. "
                . "Termine par : 'Réponds UNIQUEMENT en HTML valide, sans markdown.'";
            $res = aiCallWithKeys($userId, [['role'=>'user','content'=>$prompt]], 1500);
            if (!$res['ok']) jsonOut(['success'=>false,'error'=>$res['error']]);
            $improved = trim($res['text']);
            $improved = preg_replace('/^```[a-z]*\n?/', '', $improved);
            $improved = preg_replace('/\n?```$/', '', $improved);
            $improved = trim($improved, " \t\n\r\"'");
            jsonOut(['success'=>true, 'old_prompt'=>$tool['prompt'], 'new_prompt'=>$improved]);
        }

        // ---- THREAD OPEN ----
        if ($action === 'thread_open') {
            $contextType = $input['context_type'] ?? 'analysis'; $contextId = (int)($input['context_id'] ?? 0);
            if (!in_array($contextType, ['analysis','image','general'])) jsonOut(['success'=>false,'error'=>'Type invalide']);
            if ($contextId === 0 && $contextType !== 'general') jsonOut(['success'=>false,'error'=>'context_id requis']);
            $title = ''; $content = '';
            if ($contextType === 'analysis') {
                $stmt = db()->prepare("SELECT * FROM analyses WHERE id = ? AND user_id = ?"); $stmt->execute([$contextId, $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!$row) jsonOut(['success'=>false,'error'=>'Analyse introuvable']);
                $title = ($row['tool_emoji'] ?? '✨') . ' ' . ($row['tool_label'] ?? 'Analyse') . ' · ' . mb_substr($row['video_title'] ?? $row['input_text'] ?? 'Sans titre', 0, 60);
                $content = $row['result_html'] ?? '';
            } elseif ($contextType === 'image') {
                $stmt = db()->prepare("SELECT * FROM visual_assets WHERE id = ? AND user_id = ?"); $stmt->execute([$contextId, $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!$row) jsonOut(['success'=>false,'error'=>'Image introuvable']);
                $title = '🎨 Image générée'; $content = 'Prompt : ' . ($row['prompt'] ?? '');
            } else { $title = '💬 Discussion'; $content = ''; }
            $stmt = db()->prepare("SELECT * FROM threads WHERE user_id = ? AND context_type = ? AND context_id = ?"); $stmt->execute([$userId, $contextType, $contextId]);
            $thread = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$thread) {
                db()->prepare("INSERT INTO threads (user_id, context_type, context_id, title, context_content) VALUES (?,?,?,?,?)")->execute([$userId, $contextType, $contextId, $title, $content]);
                $threadId = db()->lastInsertId();
            } else $threadId = $thread['id'];
            $msgs = db()->prepare("SELECT role, content FROM thread_messages WHERE thread_id = ? ORDER BY id ASC LIMIT 50"); $msgs->execute([$threadId]);
            jsonOut(['success'=>true, 'thread_id'=>$threadId, 'title'=>$title, 'messages'=>$msgs->fetchAll(PDO::FETCH_ASSOC)]);
        }
        if ($action === 'thread_send') {
            $threadId = (int)($input['thread_id'] ?? 0); $msg = trim($input['message'] ?? '');
            if (!$threadId || !$msg) jsonOut(['success'=>false,'error'=>'thread_id et message requis']);
            $stmt = db()->prepare("SELECT * FROM threads WHERE id = ? AND user_id = ?"); $stmt->execute([$threadId, $userId]);
            $thread = $stmt->fetch(PDO::FETCH_ASSOC); if (!$thread) jsonOut(['success'=>false,'error'=>'Thread introuvable']);
            $key = getFirstKey($userId); if (!$key) jsonOut(['success'=>false,'error'=>'Configure une clé API']);
            db()->prepare("INSERT INTO thread_messages (thread_id, role, content) VALUES (?,?,?)")->execute([$threadId, 'user', $msg]);
            $hist = db()->prepare("SELECT role, content FROM thread_messages WHERE thread_id = ? ORDER BY id DESC LIMIT 10"); $hist->execute([$threadId]);
            $history = array_reverse($hist->fetchAll(PDO::FETCH_ASSOC));
            $sys = "Tu es ARTFORGE AI 🤖. L'utilisateur discute d'un contexte spécifique.\n\n"
                . "=== CONTEXTE ===\nTitre : {$thread['title']}\nContenu :\n" . mb_substr(strip_tags($thread['context_content'] ?? ''), 0, 1500) . "\n\n"
                . "L'utilisateur peut demander précisions, variations, améliorations, comparaisons.\n"
                . "RÈGLES : HTML valide. INTERDIT : **, #, ```, ---. Utilise <p>, <ul>, <li>, <strong>, <h3>. Français concis.";
            $messages = [['role'=>'system', 'content'=>$sys]];
            foreach ($history as $h) $messages[] = ['role'=>$h['role'], 'content'=>$h['content']];
            $res = aiCallWithKeys($userId, $messages, 2000);
            if (!$res['ok']) jsonOut(['success'=>false, 'error'=>$res['error']]);
            $html = formatAiResponse($res['text']);
            db()->prepare("INSERT INTO thread_messages (thread_id, role, content) VALUES (?,?,?)")->execute([$threadId, 'assistant', $res['text']]);
            jsonOut(['success'=>true, 'reply'=>$html]);
        }

        // ---- ADMIN OUTILS ----
        if ($action === 'save_tool') {
            $id = (int)($input['id'] ?? 0); $label = trim($input['label'] ?? ''); $emoji = trim($input['emoji'] ?? '✨');
            $prompt = trim($input['prompt'] ?? ''); $active = (int)($input['active'] ?? 1);
            if (!$id || !$label || mb_strlen($prompt) < 10) jsonOut(['success'=>false,'error'=>'Données invalides']);
            db()->prepare("UPDATE tools SET label=?, emoji=?, prompt=?, active=? WHERE id=? AND user_id=?")->execute([$label, $emoji, $prompt, $active, $id, $userId]);
            jsonOut(['success'=>true]);
        }
        if ($action === 'delete_tool') { $id = (int)($input['id'] ?? 0); db()->prepare("DELETE FROM tools WHERE id=? AND user_id=? AND is_base=0")->execute([$id, $userId]); jsonOut(['success'=>true]); }
        if ($action === 'reset_tool') {
            $id = (int)($input['id'] ?? 0);
            $stmt = db()->prepare("SELECT slug FROM tools WHERE id=? AND user_id=? AND is_base=1"); $stmt->execute([$id, $userId]);
            $slug = $stmt->fetchColumn(); if (!$slug) jsonOut(['success'=>false,'error'=>'Introuvable']);
            foreach (baseTools() as $t) if ($t[0] === $slug) { db()->prepare("UPDATE tools SET label=?, emoji=?, prompt=? WHERE id=? AND user_id=?")->execute([$t[2], $t[1], $t[4], $id, $userId]); jsonOut(['success'=>true]); }
            jsonOut(['success'=>false,'error'=>'Base introuvable']);
        }
        if ($action === 'create_tool_from_chat') {
            $message = trim($input['message'] ?? ''); if (!$message) jsonOut(['success'=>false,'error'=>'Message requis']);
            $key = getFirstKey($userId); if (!$key) jsonOut(['success'=>false,'error'=>'Configure une clé API']);
            $tools = getUserTools($userId);
            $messages = [['role'=>'system', 'content'=>buildChatSystemPrompt($tools)], ['role'=>'user', 'content'=>$message]];
            $res = aiCallWithKeys($userId, $messages, 2000);
            if (!$res['ok']) jsonOut(['success'=>false, 'error'=>$res['error']]);
            $aiText = $res['text']; $created = null;
            $toolJson = extractToolFromResponse($aiText);
            if ($toolJson) {
                $info = createCustomTool($userId, $toolJson);
                $created = array_merge($toolJson, ['id'=>$info['id'], 'slug'=>$info['slug']]);
                $aiText = preg_replace('/```create_tool\s*\{.*?\}\s*```/s', '', $aiText);
                $aiText = preg_replace('/<create_tool>\s*\{.*?\}\s*<\/create_tool>/s', '', $aiText);
            }
            jsonOut(['success'=>true, 'reply'=>formatAiResponse($aiText), 'created_tool'=>$created]);
        }

        // ---- PACKS IA ----
        if ($action === 'create_pack') {
            $brief=trim($input['description']??''); if(mb_strlen($brief)<8) jsonOut(['success'=>false,'error'=>'Décris le pack plus précisément.']);
            $sys='Tu conçois des outils ARTFORGE. Catégories : image=img→txt, text=txt→txt, combined=image+texte, visual=txt→img. Réponds UNIQUEMENT en JSON : {"name":"...","description":"...","tools":[{"slug":"snake_case","label":"...","emoji":"...","category":"image|text|combined|visual","prompt":"consigne complète"}]}. Génère 4 à 12 outils distincts, complémentaires et réellement exécutables. N’invente aucune catégorie.';
            $r=aiCallWithKeys($userId,[['role'=>'system','content'=>$sys],['role'=>'user','content'=>$brief]],5000);
            if(!$r['ok']) jsonOut(['success'=>false,'error'=>$r['error']]); $j=parseAiJson($r['text']); $arr=$j['tools']??[];
            if(!is_array($arr)||!$arr) jsonOut(['success'=>false,'error'=>'Réponse IA invalide : aucun outil exploitable.']);
            $name=mb_substr(trim($j['name']??'Pack personnalisé'),0,90);
            db()->prepare("INSERT INTO tool_packs(user_id,name,description) VALUES(?,?,?)")->execute([$userId,$name,mb_substr($j['description']??$brief,0,500)]); $packId=(int)db()->lastInsertId(); $created=[];
            foreach(array_slice($arr,0,20) as $t){if(!is_array($t)||empty($t['label'])||empty($t['prompt'])||!validToolCategory($t['category']??''))continue;$t['pack_id']=$packId;$t['slug']=$t['slug']??('pack_'.$packId.'_'.count($created));$info=createCustomTool($userId,$t);$created[]=['id'=>$info['id'],'slug'=>$info['slug'],'label'=>$t['label'],'category'=>$t['category']];}
            if(!$created){db()->prepare("DELETE FROM tool_packs WHERE id=? AND user_id=?")->execute([$packId,$userId]);jsonOut(['success'=>false,'error'=>'Aucun outil valide dans la réponse IA.']);}
            jsonOut(['success'=>true,'pack'=>['id'=>$packId,'name'=>$name],'tools'=>$created]);
        }
        if ($action === 'list_packs') {$st=db()->prepare("SELECT p.id,p.name,p.description,p.created_at,COUNT(t.id) tool_count FROM tool_packs p LEFT JOIN tools t ON t.pack_id=p.id AND t.user_id=p.user_id WHERE p.user_id=? GROUP BY p.id ORDER BY p.created_at DESC");$st->execute([$userId]);jsonOut(['success'=>true,'packs'=>$st->fetchAll(PDO::FETCH_ASSOC)]);}
        if ($action === 'delete_pack') {$id=(int)($input['id']??0);$chk=db()->prepare("SELECT name FROM tool_packs WHERE id=? AND user_id=?");$chk->execute([$id,$userId]);if($chk->fetchColumn()==='Pack de base — ARTFORGE')jsonOut(['success'=>false,'error'=>'Le pack de base est protégé.']);db()->beginTransaction();db()->prepare("DELETE FROM tools WHERE user_id=? AND pack_id=? AND is_base=0")->execute([$userId,$id]);db()->prepare("DELETE FROM tool_packs WHERE user_id=? AND id=?")->execute([$userId,$id]);db()->commit();jsonOut(['success'=>true]);}
        if ($action === 'optimize_all_tools') {
            $st=db()->prepare("SELECT slug,label,category,prompt FROM tools WHERE user_id=? ORDER BY id");$st->execute([$userId]);$all=$st->fetchAll(PDO::FETCH_ASSOC);$done=0;$failed=[];
            foreach(array_chunk($all,6) as $chunk){$payload=$chunk;$sys='Améliore les prompts de ces outils ARTFORGE sans changer slug, objectif ni catégorie. Sois précis, approfondi, opérationnel, structure critères et format de sortie. image=img→txt, text=txt→txt, combined=image+texte, visual=txt→img. Réponds UNIQUEMENT en JSON : {"tools":[{"slug":"slug identique","prompt":"nouveau prompt"}]}.';$rr=aiCallWithKeys($userId,[['role'=>'system','content'=>$sys],['role'=>'user','content'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],5000);$jj=$rr['ok']?parseAiJson($rr['text']):null;if(!$jj||empty($jj['tools'])){$failed=array_merge($failed,array_column($chunk,'slug'));continue;}foreach($jj['tools'] as $it)if(!empty($it['slug'])&&!empty($it['prompt'])){$up=db()->prepare("UPDATE tools SET prompt=? WHERE user_id=? AND slug=?");$up->execute([mb_substr(trim($it['prompt']),0,12000),$userId,$it['slug']]);if($up->rowCount())$done++;}}
            jsonOut(['success'=>true,'optimized'=>$done,'failed'=>$failed]);
        }
        if ($action === 'bulk_prompt_list') {
            $brief=trim($input['brief']??'');$count=max(1,min(30,(int)($input['count']??10)));if(!$brief)jsonOut(['success'=>false,'error'=>'Décris la série d’images.']);
            $sys="Tu es directeur artistique. Produis exactement {$count} prompts txt→img distincts. Garde le sujet commun mais varie réellement styles, cadrages, lumières, compositions, décors et directions artistiques. Réponds UNIQUEMENT en JSON : {\"prompts\":[\"prompt 1\"]}.";
            $rr=aiCallWithKeys($userId,[['role'=>'system','content'=>$sys],['role'=>'user','content'=>$brief]],5000);if(!$rr['ok'])jsonOut(['success'=>false,'error'=>$rr['error']]);$jj=parseAiJson($rr['text']);$prompts=$jj['prompts']??[];if(!is_array($prompts)||!$prompts)jsonOut(['success'=>false,'error'=>'Liste de prompts non exploitable.']);jsonOut(['success'=>true,'prompts'=>array_values(array_slice(array_filter($prompts,'is_string'),0,$count))]);
        }
        if ($action === 'generate_image_one') {
            $prompt=trim($input['prompt']??'');if(mb_strlen($prompt)<5)jsonOut(['success'=>false,'error'=>'Prompt trop court.']);$keys=getUserKeys($userId);if(!$keys)jsonOut(['success'=>false,'error'=>'Configure une clé API.']);$start=max(0,(int)($input['key_index']??0));$errors=[];$res=null;
            for($i=0;$i<count($keys);$i++){$k=($start+$i)%count($keys);$try=generateImage($keys[$k],$prompt,$input['size']??'1024x1024');if($try['ok']){$res=$try;break;}$errors[]='Clé '.($k+1).' : '.$try['error'];}
            if(!$res)jsonOut(['success'=>false,'error'=>implode(' | ',array_slice($errors,0,3))]);$assetId=saveGeneratedAsset($userId,$prompt,$res);$img=!empty($res['b64'])?'data:'.($res['mime']??'image/png').';base64,'.$res['b64']:($res['url']??'');jsonOut(['success'=>true,'asset_id'=>$assetId,'image'=>$img,'prompt'=>$prompt]);
        }

        if ($action === 'asset_image') {
            $id=(int)($input['id']??0);$st=db()->prepare("SELECT image_b64,image_url,mime FROM visual_assets WHERE id=? AND user_id=?");$st->execute([$id,$userId]);$a=$st->fetch(PDO::FETCH_ASSOC);if(!$a)jsonOut(['success'=>false,'error'=>'Image introuvable']);
            $src=!empty($a['image_b64'])?'data:'.($a['mime']?:'image/png').';base64,'.$a['image_b64']:($a['image_url']??'');jsonOut(['success'=>true,'image'=>$src]);
        }

        // ---- LIBRARY (fix LEFT → substr) ----
        if ($action === 'library') {
            $page = max(1, (int)($input['page'] ?? 1)); $perPage = min(50, max(5, (int)($input['per_page'] ?? 10)));
            $type = $input['type'] ?? 'all'; $offset = ($page - 1) * $perPage;
            $totalA = 0; $totalI = 0;
            if ($type === 'all' || $type === 'analyses') { $stmt = db()->prepare("SELECT COUNT(*) FROM analyses WHERE user_id = ?"); $stmt->execute([$userId]); $totalA = (int)$stmt->fetchColumn(); }
            if ($type === 'all' || $type === 'images') { $stmt = db()->prepare("SELECT COUNT(*) FROM visual_assets WHERE user_id = ?"); $stmt->execute([$userId]); $totalI = (int)$stmt->fetchColumn(); }
            $total = $totalA + $totalI;
            if ($type === 'analyses') {
                $sql = "SELECT id, 'analysis' as kind, tool_emoji as emoji, tool_label as label, COALESCE(video_title, substr(input_text,1,80)) as title, video_url as url, thumbnail, result_html as content, created_at FROM analyses WHERE user_id = ? ORDER BY created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
                $params = [$userId];
            } elseif ($type === 'images') {
                $sql = "SELECT id, 'image' as kind, '🎨' as emoji, 'Image générée' as label, prompt as title, NULL as url, NULL as thumbnail, prompt as content, created_at FROM visual_assets WHERE user_id = ? ORDER BY created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
                $params = [$userId];
            } else {
                $sql = "SELECT * FROM (SELECT id, 'analysis' as kind, tool_emoji as emoji, tool_label as label, COALESCE(video_title, substr(input_text,1,80)) as title, video_url as url, thumbnail, result_html as content, created_at FROM analyses WHERE user_id = ? UNION ALL SELECT id, 'image' as kind, '🎨' as emoji, 'Image générée' as label, prompt as title, NULL as url, NULL as thumbnail, prompt as content, created_at FROM visual_assets WHERE user_id = ?) ORDER BY created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
                $params = [$userId, $userId];
            }
            $stmt = db()->prepare($sql); $stmt->execute($params);
            jsonOut(['success'=>true, 'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total, 'page'=>$page, 'per_page'=>$perPage, 'pages'=>ceil($total/$perPage)]);
        }

        jsonOut(['success'=>false,'error'=>'Action inconnue']);
    } catch (Throwable $e) {
        error_log("API [$action] : " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
        jsonOut(['success'=>false, 'error'=>'Erreur : '.$e->getMessage()]);
    }
}

// =================================================================
// RENDU
// =================================================================
$currentUser = null; $profile = null; $apiKeys = []; $tools = []; $chatMessages = [];
if (isset($_SESSION['user_id'])) {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($currentUser) {
        ensureBaseTools($currentUser['id']);
        $ks = db()->prepare("SELECT api_key FROM api_keys WHERE user_id = ? ORDER BY id"); $ks->execute([$currentUser['id']]);
        $apiKeys = $ks->fetchAll(PDO::FETCH_COLUMN);
        if (empty($apiKeys)) {
            $ck = loadKeysCookie();
            if (!empty($ck)) { $ins = db()->prepare("INSERT INTO api_keys (user_id, api_key) VALUES (?,?)"); foreach ($ck as $k) $ins->execute([$currentUser['id'], $k]); $apiKeys = $ck; }
        }
        $tools = getUserTools($currentUser['id']);
        $cm = db()->prepare("SELECT role, content FROM chat_messages WHERE user_id = ? ORDER BY id ASC LIMIT 100"); $cm->execute([$currentUser['id']]);
        $chatMessages = $cm->fetchAll(PDO::FETCH_ASSOC);
        if (!isset($_SESSION['profile_cache']) || $_SESSION['profile_cache']['user'] !== $currentUser['tiktok_username'] || time() - $_SESSION['profile_cache']['time'] > 900) {
            $p = fetchProfileInfo($currentUser['tiktok_username']);
            if ($p) $_SESSION['profile_cache'] = ['user'=>$currentUser['tiktok_username'],'data'=>$p,'time'=>time()];
        }
        $profile = $_SESSION['profile_cache']['data'] ?? null;
    }
}
$keysCount = count($apiKeys);
$lastUserCookie = $_COOKIE['artforge_last_user'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ARTFORGE AI v6 ✨</title>
<style>
:root{--bg1:#0a0618;--bg2:#16072e;--bg3:#052b3d;--card:rgba(255,255,255,.04);--card-border:rgba(255,255,255,.08);--text:#f5f5ff;--muted:#a0a0c0;--pink:#fe2c55;--cyan:#25f4ee;--purple:#a855f7;--ok:#22c55e;--err:#ef4444;--warn:#f59e0b;--radius:16px}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0;min-height:100vh}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,Roboto,sans-serif;color:var(--text);background:radial-gradient(ellipse at top left,var(--bg2) 0%,var(--bg1) 40%,var(--bg3) 100%) fixed;background-attachment:fixed;line-height:1.5;padding:1rem;display:flex;justify-content:center;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:-1;background:radial-gradient(circle at 10% 20%,rgba(254,44,85,.15),transparent 40%),radial-gradient(circle at 90% 80%,rgba(37,244,238,.12),transparent 40%),radial-gradient(circle at 50% 50%,rgba(168,85,247,.1),transparent 50%);pointer-events:none}
.wrap{width:100%;max-width:1100px;position:relative}
.card{background:var(--card);border:1px solid var(--card-border);border-radius:var(--radius);padding:1.5rem;margin-bottom:1.25rem;backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);box-shadow:0 8px 32px rgba(0,0,0,.3);transition:transform .2s,border-color .2s}
.card:hover{border-color:rgba(254,44,85,.3)}
h1{margin:0 0 .5rem;font-size:clamp(1.5rem,4vw,2.2rem);text-align:center;font-weight:800;letter-spacing:-.02em}
h1 .grad{background:linear-gradient(90deg,var(--pink),var(--purple),var(--cyan));-webkit-background-clip:text;background-clip:text;color:transparent}
h2{font-size:1.15rem;margin:0 0 1rem;display:flex;align-items:center;gap:.5rem;font-weight:700}
h3{font-size:1rem;margin:1.2rem 0 .7rem;color:var(--cyan);font-weight:700}
p.sub{text-align:center;color:var(--muted);margin:0 0 1.5rem;font-size:.95rem}
input,textarea,button,select{font-family:inherit;font-size:.95rem}
input[type=url],input[type=text],textarea,select{width:100%;padding:13px 15px;border:2px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(0,0,0,.3);color:var(--text)}
input::placeholder,textarea::placeholder{color:rgba(160,160,192,.6)}
input:focus,textarea:focus,select:focus{outline:none;border-color:var(--pink);box-shadow:0 0 0 3px rgba(254,44,85,.15)}
textarea{resize:vertical;min-height:100px}
button{padding:12px 22px;background:linear-gradient(135deg,var(--pink),var(--purple));color:#fff;border:none;border-radius:12px;font-weight:700;cursor:pointer;transition:transform .15s,box-shadow .2s,opacity .2s;box-shadow:0 4px 16px rgba(254,44,85,.3)}
button:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 24px rgba(254,44,85,.5)}
button:disabled{opacity:.5;cursor:not-allowed}
button.sec{background:rgba(255,255,255,.08);box-shadow:none}
button.sec:hover:not(:disabled){background:rgba(255,255,255,.15);box-shadow:none}
button.sm{padding:8px 14px;font-size:.85rem;border-radius:9px}
button.ghost{background:transparent;box-shadow:none;border:1px solid rgba(255,255,255,.15)}
.tabs{display:flex;gap:.4rem;margin-bottom:1.2rem;border-bottom:1px solid var(--card-border);overflow-x:auto;padding-bottom:.2rem;scrollbar-width:none}
.tabs::-webkit-scrollbar{display:none}
.tabs button{background:transparent;color:var(--muted);border-radius:12px 12px 0 0;padding:.7rem 1rem;box-shadow:none;font-weight:600;white-space:nowrap;position:relative}
.tabs button:hover{color:var(--text);background:rgba(255,255,255,.03);transform:none;box-shadow:none}
.tabs button.active{color:var(--pink)}
.tabs button.active::after{content:'';position:absolute;bottom:-2px;left:10%;right:10%;height:3px;background:linear-gradient(90deg,var(--pink),var(--purple));border-radius:3px}
.hidden{display:none!important}
.row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
.grid-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.6rem;margin:1rem 0}
.tool-card{background:rgba(255,255,255,.03);padding:.9rem;border-radius:12px;border:2px solid transparent;cursor:pointer;transition:all .2s;display:flex;align-items:flex-start;gap:.6rem;position:relative}
.tool-card:hover{background:rgba(254,44,85,.08);border-color:rgba(254,44,85,.3)}
.tool-card.selected{background:linear-gradient(135deg,rgba(254,44,85,.15),rgba(168,85,247,.15));border-color:var(--pink)}
.tool-card.disabled{opacity:.35;cursor:not-allowed}
.tool-card .emoji{font-size:1.4rem}
.tool-card .info{flex:1;min-width:0}
.tool-card .label{font-weight:600;font-size:.9rem}
.tool-card .cat{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
.tool-card .check-indicator{position:absolute;top:.5rem;right:.5rem;width:18px;height:18px;border-radius:50%;border:2px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:.7rem;color:transparent;transition:all .2s}
.tool-card.selected .check-indicator{background:var(--pink);border-color:var(--pink);color:#fff}
.tool-card.is-custom::before{content:'✨';position:absolute;top:.4rem;right:1.8rem;font-size:.75rem;opacity:.8}
.profile-header{display:flex;gap:1.2rem;align-items:center;flex-wrap:wrap}
.profile-header img{width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--pink)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:.6rem;margin-top:1rem}
.stat-item{background:rgba(255,255,255,.04);padding:.8rem .5rem;border-radius:12px;text-align:center}
.stat-value{font-size:1.2rem;font-weight:800;background:linear-gradient(90deg,var(--pink),var(--cyan));-webkit-background-clip:text;background-clip:text;color:transparent;display:block}
.stat-label{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
.msg{padding:.9rem 1rem;border-radius:12px;font-size:.9rem;margin:.6rem 0;border:1px solid}
.msg.err{background:rgba(239,68,68,.1);color:#fca5a5;border-color:rgba(239,68,68,.3)}
.msg.ok{background:rgba(34,197,94,.1);color:#86efac;border-color:rgba(34,197,94,.3)}
.msg.info{background:rgba(37,244,238,.08);color:#a5f3fc;border-color:rgba(37,244,238,.3)}
.msg.warn{background:rgba(245,158,11,.1);color:#fcd34d;border-color:rgba(245,158,11,.3)}
.thumb-preview{max-width:180px;border-radius:12px;border:2px solid var(--card-border);margin-top:.5rem}
.result-card{background:rgba(255,255,255,.03);border:1px solid var(--card-border);border-radius:14px;padding:1.2rem;margin-bottom:1rem;animation:slideIn .3s ease-out;position:relative}
@keyframes slideIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.result-card .card-actions{position:absolute;top:.8rem;right:.8rem;display:flex;gap:.3rem}
.result-card .card-actions button{padding:.35rem .7rem;font-size:.75rem;border-radius:8px}
.result-card h4{margin:0 0 .8rem;font-size:1rem;display:flex;align-items:center;gap:.5rem;padding-right:120px}
.result-card h4 .emoji{font-size:1.4rem}
.ai-resp{background:rgba(0,0,0,.25);padding:1rem;border-radius:10px;border-left:3px solid var(--pink);margin-bottom:.6rem;font-size:.9rem;line-height:1.6;overflow-x:auto}
.ai-resp h1,.ai-resp h2,.ai-resp h3{margin:.5rem 0;font-size:1rem}
.ai-resp ul,.ai-resp ol{padding-left:1.2rem;margin:.4rem 0}
.ai-resp li{margin-bottom:.2rem}
.ai-resp p{margin:.3rem 0}
.ai-resp strong{color:var(--cyan)}
.ai-resp em{color:var(--muted)}
.ai-resp code{background:rgba(255,255,255,.1);padding:1px 6px;border-radius:4px;font-size:.85em}
.ai-resp img{max-width:100%;border-radius:8px;margin:.5rem 0;display:block}
.keys-badge{background:rgba(34,197,94,.15);color:#86efac;font-size:.75rem;padding:.25rem .6rem;border-radius:20px;font-weight:600}
.badge{font-size:.7rem;padding:2px 8px;border-radius:5px;font-weight:600;text-transform:uppercase}
.badge.base{background:rgba(168,85,247,.2);color:#d8b4fe}
.badge.custom{background:rgba(37,244,238,.2);color:#a5f3fc}
.badge.img{background:rgba(254,44,85,.2);color:#fda4af}
.badge.txt{background:rgba(245,158,11,.2);color:#fcd34d}
.badge.combo{background:rgba(34,197,94,.2);color:#86efac}
.badge.visual{background:rgba(168,85,247,.2);color:#d8b4fe}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
.loader{text-align:center;padding:2rem;color:var(--muted)}
.chat-container{display:flex;flex-direction:column;height:480px}
.chat-messages{flex:1;overflow-y:auto;padding:.5rem;display:flex;flex-direction:column;gap:.8rem}
.chat-messages::-webkit-scrollbar{width:6px}
.chat-messages::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px}
.chat-msg{max-width:85%;padding:.8rem 1rem;border-radius:14px;font-size:.9rem;line-height:1.5;word-wrap:break-word}
.chat-msg.user{align-self:flex-end;background:linear-gradient(135deg,var(--pink),var(--purple));color:#fff;border-bottom-right-radius:4px}
.chat-msg.assistant{align-self:flex-start;background:rgba(255,255,255,.06);border:1px solid var(--card-border);border-bottom-left-radius:4px}
.chat-msg.assistant .ai-resp{border-left:none;background:transparent;padding:0;margin:0}
.chat-input-row{display:flex;gap:.6rem;margin-top:.8rem}
.chat-input-row textarea{min-height:50px;max-height:120px}
.tool-created-card{align-self:center;background:linear-gradient(135deg,rgba(34,197,94,.15),rgba(37,244,238,.15));border:2px solid rgba(34,197,94,.4);padding:.8rem 1.2rem;border-radius:14px;text-align:center;font-size:.9rem;animation:pop .4s ease-out}
@keyframes pop{0%{transform:scale(.8);opacity:0}100%{transform:scale(1);opacity:1}}
.admin-tool{background:rgba(255,255,255,.03);border:1px solid var(--card-border);border-radius:12px;padding:1rem;margin-bottom:.8rem}
.admin-tool.inactive{opacity:.5}
.admin-tool .header{display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap}
.admin-tool .header .title{font-weight:700;display:flex;align-items:center;gap:.5rem}
.header-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;gap:.5rem;flex-wrap:wrap}
.header-bar .brand{font-weight:800;font-size:1.2rem;background:linear-gradient(90deg,var(--pink),var(--cyan));-webkit-background-clip:text;background-clip:text;color:transparent}
.header-bar .user-chip{background:rgba(255,255,255,.06);padding:.4rem .9rem;border-radius:20px;font-size:.85rem}
.turbo-toggle{display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--muted);margin:.8rem 0;cursor:pointer}
.turbo-toggle input{accent-color:var(--pink);width:18px;height:18px}
.lib-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.8rem}
.lib-card{background:rgba(255,255,255,.03);border:1px solid var(--card-border);border-radius:12px;padding:.8rem;position:relative;transition:border-color .2s}
.lib-card:hover{border-color:rgba(254,44,85,.4)}
.lib-card .meta{font-size:.75rem;color:var(--muted);margin-bottom:.3rem;padding-right:60px}
.lib-card .title{font-weight:600;font-size:.9rem;margin-bottom:.3rem}
.lib-card .snippet{font-size:.8rem;color:var(--muted);line-height:1.4;max-height:80px;overflow:hidden}
.lib-card img{width:100%;max-height:120px;object-fit:cover;border-radius:8px;margin-top:.4rem}
.lib-card .lib-actions{position:absolute;top:.6rem;right:.6rem;display:flex;gap:.2rem}
.lib-card .lib-actions button{padding:.25rem .5rem;font-size:.7rem;border-radius:6px}
.pagination{display:flex;justify-content:center;gap:.4rem;margin-top:1rem;flex-wrap:wrap}
.pagination button{padding:.4rem .8rem;font-size:.85rem}
.pagination button.active{background:var(--pink);box-shadow:0 0 0 2px rgba(254,44,85,.4)}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(8px);z-index:1000;display:flex;align-items:center;justify-content:center;padding:1rem;animation:fadeIn .2s}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{background:linear-gradient(135deg,#1a0b2e,#0a0618);border:1px solid var(--card-border);border-radius:16px;width:100%;max-width:720px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.5);animation:popIn .25s;overflow:hidden}
@keyframes popIn{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}
.modal-header{padding:1rem 1.2rem;border-bottom:1px solid var(--card-border);display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-shrink:0}
.modal-header h3{margin:0;color:var(--text);font-size:1rem;flex:1}
.modal-header .close-btn{background:transparent;border:none;color:var(--muted);font-size:1.5rem;padding:0 .3rem;cursor:pointer;box-shadow:none;line-height:1}
.modal-header .close-btn:hover{color:var(--pink);transform:none;box-shadow:none}
.modal-body{flex:1;overflow-y:auto;padding:1rem 1.2rem}
.modal-footer{padding:.8rem 1.2rem;border-top:1px solid var(--card-border);display:flex;gap:.5rem;flex-wrap:wrap;flex-shrink:0}
.modal-thread .chat-messages{max-height:50vh}
.modal-context-preview{background:rgba(254,44,85,.05);border-left:3px solid var(--pink);padding:.7rem;border-radius:8px;font-size:.85rem;color:var(--muted);max-height:150px;overflow-y:auto;margin-bottom:.8rem}
.prompt-compare{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin:1rem 0}
.prompt-compare>div{background:rgba(0,0,0,.3);padding:.8rem;border-radius:10px}
.prompt-compare h4{margin:0 0 .5rem;font-size:.85rem;color:var(--muted)}
.prompt-compare .new h4{color:var(--cyan)}
.prompt-compare pre{white-space:pre-wrap;font-family:inherit;font-size:.85rem;line-height:1.5;margin:0;color:var(--muted);max-height:300px;overflow-y:auto}
.prompt-compare .new pre{color:var(--text)}
@media(max-width:640px){body{padding:.5rem}.card{padding:1rem}.grid-checks{grid-template-columns:1fr 1fr}.chat-container{height:400px}.lib-grid{grid-template-columns:1fr}.prompt-compare{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

<?php if (!$currentUser): ?>
<div style="text-align:center;margin:2rem 0 1rem">
    <h1><span class="grad">✨ ARTFORGE AI v6 ✨</span></h1>
    <p class="sub">Crée, analyse, monétise. Ton studio TikTok propulsé par l'IA.</p>
</div>
<div class="card">
    <div class="tabs">
        <button id="tab-register" class="active" onclick="showAuthTab('register')">🚀 Inscription</button>
        <button id="tab-login" onclick="showAuthTab('login')">🔑 Connexion</button>
    </div>
    <div id="form-register">
        <label style="font-weight:600;font-size:.9rem">Lien TikTok</label>
        <input type="url" id="reg-url" placeholder="https://www.tiktok.com/@toi/video/..." style="margin-top:.4rem">
        <label style="font-weight:600;font-size:.9rem;display:block;margin-top:1rem">Code PIN (4 chiffres)</label>
        <div class="row" style="margin-top:.4rem">
            <input type="text" id="reg-pin" maxlength="4" inputmode="numeric" placeholder="0000" style="max-width:120px">
            <button onclick="doRegister()" id="btn-register">Créer</button>
        </div>
        <div id="reg-msg"></div>
    </div>
    <div id="form-login" class="hidden">
        <label style="font-weight:600;font-size:.9rem">Nom TikTok</label>
        <input type="text" id="log-user" placeholder="ton_pseudo" value="<?= htmlspecialchars($lastUserCookie) ?>" style="margin-top:.4rem">
        <label style="font-weight:600;font-size:.9rem;display:block;margin-top:1rem">Code PIN</label>
        <div class="row" style="margin-top:.4rem">
            <input type="text" id="log-pin" maxlength="4" inputmode="numeric" placeholder="0000" style="max-width:120px">
            <button onclick="doLogin()" id="btn-login">Se connecter</button>
        </div>
        <div id="log-msg"></div>
    </div>
</div>
<?php else: ?>

<div class="header-bar">
    <div class="brand">✨ ARTFORGE AI v5</div>
    <div class="row">
        <div class="user-chip">👤 @<?= htmlspecialchars($currentUser['tiktok_username']) ?></div>
        <button class="sec sm" onclick="doLogout()">Déconnexion</button>
    </div>
</div>

<div class="card" style="padding:1rem 1.5rem">
    <div class="tabs" style="margin-bottom:0;border:none">
        <button class="active" data-view="analyze" onclick="switchView('analyze')">🎯 Analyser</button>
        <button data-view="bulk" onclick="switchView('bulk')">📦 Bulk</button>
        <button data-view="chat" onclick="switchView('chat')">💬 Chat</button>
        <button data-view="admin" onclick="switchView('admin')">⚙️ Outils</button>
        <button data-view="packs" onclick="switchView('packs')">🧩 Packs IA</button>
        <button data-view="library" onclick="switchView('library')">📚 Bibliothèque</button>
        <button data-view="keys" onclick="switchView('keys')">🔑 Clés</button>
    </div>
</div>

<div id="view-analyze">
    <div class="card">
        <h2>👤 Mon profil</h2>
        <?php if ($profile): ?>
        <div class="profile-header">
            <img src="<?= htmlspecialchars($profile['avatar']) ?>" alt="avatar" onerror="this.style.display='none'">
            <div style="flex:1;min-width:200px">
                <div style="font-size:1.1rem;font-weight:700"><?= htmlspecialchars($profile['nickname']) ?></div>
                <div style="color:var(--muted);font-size:.85rem">@<?= htmlspecialchars($profile['uniqueId']) ?></div>
                <div style="color:var(--muted);font-size:.8rem;margin-top:.3rem"><?= htmlspecialchars($profile['bio']) ?></div>
            </div>
        </div>
        <div class="stats-grid">
            <div class="stat-item"><span class="stat-value"><?= number_format($profile['followers'],0,',',' ') ?></span><span class="stat-label">Abonnés</span></div>
            <div class="stat-item"><span class="stat-value"><?= number_format($profile['likes'],0,',',' ') ?></span><span class="stat-label">Likes</span></div>
            <div class="stat-item"><span class="stat-value"><?= number_format($profile['videoCount'],0,',',' ') ?></span><span class="stat-label">Vidéos</span></div>
            <div class="stat-item"><span class="stat-value"><?= number_format($profile['following'],0,',',' ') ?></span><span class="stat-label">Abonnements</span></div>
        </div>
        <?php else: ?><div class="msg info">Profil indisponible.</div><?php endif; ?>
    </div>
    <div class="card">
        <h2>🎯 Studio d'analyse</h2>
        <?php if (empty($apiKeys)): ?><div class="msg warn">⚠️ Configure tes clés API.</div><?php endif; ?>
        <label style="font-weight:600;font-size:.9rem">🔗 Lien vidéo TikTok</label>
        <div class="row" style="margin-top:.4rem">
            <input type="url" id="video-url" placeholder="https://www.tiktok.com/@toi/video/..." value="<?= htmlspecialchars($currentUser['initial_video_url'] ?? '') ?>" style="flex:1;min-width:200px">
            <button class="sec" onclick="loadVideo()" id="btn-load">🔍 Charger</button>
        </div>
        <div id="video-info"></div>
        <h3 style="margin-top:1.5rem">📝 Texte</h3>
        <textarea id="user-text" placeholder="Colle un texte ici."></textarea>
        <h3 style="margin-top:1.5rem">🛠️ Outils à exécuter</h3>
        <div class="row" style="margin-bottom:.5rem">
            <button class="ghost sm" onclick="selectAllTools(true)">✓ Tout</button>
            <button class="ghost sm" onclick="selectAllTools(false)">✗ Rien</button>
            <span style="color:var(--muted);font-size:.85rem;margin-left:auto"><span id="selected-count">0</span> sélectionné(s)</span>
        </div>
        <div class="grid-checks" id="tools-grid">
            <?php foreach ($tools as $t): ?>
            <div class="tool-card <?= $t['is_base']?'':'is-custom' ?>" data-slug="<?= htmlspecialchars($t['slug']) ?>" data-category="<?= htmlspecialchars($t['category']) ?>" onclick="toggleTool(this)">
                <div class="emoji"><?= htmlspecialchars($t['emoji']) ?></div>
                <div class="info">
                    <div class="label"><?= htmlspecialchars($t['label']) ?></div>
                    <div class="cat"><?= $t['category']==='image'?'📸 Image':($t['category']==='text'?'📝 Texte':($t['category']==='combined'?'🎯 Combiné':'🎨 Visuel')) ?></div>
                </div>
                <div class="check-indicator">✓</div>
            </div>
            <?php endforeach; ?>
        </div>
        <label class="turbo-toggle"><input type="checkbox" id="turbo"> ⚡ Turbo : chaque outil sur toutes les clés</label>
        <button onclick="runAnalyze()" id="btn-analyze" style="width:100%;padding:14px;font-size:1rem">🚀 Lancer l'analyse</button>
        <div id="analyze-msg" style="margin-top:1rem"></div>
        <div id="analyze-results" style="margin-top:1rem"></div>
    </div>
</div>

<div id="view-bulk" class="hidden">
    <div class="card">
        <h2>📦 Studio Bulk</h2><div class="msg info">Analyse TikTok et génération d’images txt→img en série.</div>
        <textarea id="bulk-urls" placeholder="Une URL par ligne" style="min-height:160px;font-family:monospace"></textarea>
        <div class="row" style="margin-top:.6rem">
            <select id="bulk-tools" multiple style="flex:1;padding:10px;border-radius:12px;background:rgba(0,0,0,.3);color:var(--text);border:2px solid rgba(255,255,255,.1);min-height:100px">
                <?php foreach ($tools as $t): if (!$t['active']) continue; ?><option value="<?= htmlspecialchars($t['slug']) ?>"><?= htmlspecialchars($t['emoji'].' '.$t['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="row" style="margin-top:.6rem">
            <button onclick="runBulk()" id="btn-bulk">🚀 Lancer</button>
            <button class="sec" onclick="bulkAbort=true" id="btn-bulk-stop" disabled>⏹ Stop</button>
        </div>
        <div id="bulk-status" style="margin-top:.6rem;font-size:.85rem;color:var(--muted)"></div><div id="bulk-results" style="margin-top:1rem"></div>
        <hr style="border-color:var(--card-border);margin:1.5rem 0"><h3>🎨 Générateur d’images en bulk (txt → img)</h3>
        <textarea id="image-bulk-brief" placeholder="Ex. Série de portraits de mode futuriste, chaque image dans une direction photographique différente…"></textarea>
        <div class="row" style="margin:.5rem 0"><label>Nombre</label><input id="image-bulk-count" type="number" min="1" max="30" value="10" style="max-width:90px"><button class="sec sm" onclick="makeBulkPrompts()" id="btn-make-prompts">✨ Générer les prompts avec IA</button></div>
        <textarea id="image-bulk-prompts" placeholder="Un prompt par ligne, modifiable avant génération." style="min-height:160px"></textarea>
        <div class="row" style="margin-top:.5rem"><button onclick="runImageBulk()" id="btn-image-bulk">🚀 Générer les images</button><button class="sec" onclick="imageBulkAbort=true" id="btn-image-stop" disabled>⏹ Stop</button></div>
        <div id="image-bulk-status" class="msg info hidden"></div><div id="image-bulk-results" class="lib-grid"></div>
    </div>
</div>

<div id="view-chat" class="hidden">
    <div class="card">
        <h2>💬 Chat créateur d'outils</h2>
        <p style="color:var(--muted);font-size:.9rem;margin:0 0 1rem">Demande un nouvel outil, une image, ou discute.</p>
        <div class="chat-container">
            <div class="chat-messages" id="chat-messages">
                <?php foreach ($chatMessages as $m): ?>
                <div class="chat-msg <?= $m['role']==='user'?'user':'assistant' ?>">
                    <?php if ($m['role']==='user'): ?><?= htmlspecialchars($m['content']) ?>
                    <?php else: ?><div class="ai-resp"><?= formatAiResponse($m['content']) ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($chatMessages)): ?>
                <div class="chat-msg assistant"><div class="ai-resp"><p>Salut ! 👋 Je crée des outils pour toi.</p><ul><li>« Un outil qui note l'originalité sur 100 »</li><li>« Génère une miniature TikTok »</li><li>« Un outil qui traduit en vietnamien et crée une affiche »</li></ul></div></div>
                <?php endif; ?>
            </div>
            <div class="chat-input-row">
                <textarea id="chat-input" placeholder="Décris..." rows="2"></textarea>
                <button onclick="sendChat()" id="btn-chat-send">Envoyer</button>
            </div>
        </div>
        <div id="chat-status" style="margin-top:.5rem"></div>
    </div>
</div>

<div id="view-admin" class="hidden">
    <div class="card">
        <div class="row" style="justify-content:space-between;margin-bottom:1rem">
            <h2 style="margin:0">⚙️ Mes outils (<span id="tools-count"><?= count($tools) ?></span>)</h2>
            <button class="sm" onclick="openCreateToolModal()">✨ Créer un outil via IA</button>
        </div>
        <p style="color:var(--muted);font-size:.85rem;margin:0 0 1rem">🟣 base · ✨ custom · ✨ Améliorer avec IA disponible.</p>
        <div id="admin-tools">
            <?php foreach ($tools as $t): ?>
            <div class="admin-tool <?= $t['active']?'':'inactive' ?>" data-id="<?= (int)$t['id'] ?>">
                <div class="header">
                    <div class="title">
                        <input type="text" value="<?= htmlspecialchars($t['emoji']) ?>" style="width:45px;text-align:center;padding:4px" class="emoji-edit">
                        <input type="text" value="<?= htmlspecialchars($t['label']) ?>" style="padding:4px 8px;max-width:280px" class="label-edit">
                    </div>
                    <div class="row">
                        <span class="badge <?= $t['is_base']?'base':'custom' ?>"><?= $t['is_base']?'BASE':'CUSTOM' ?></span>
                        <span class="badge <?= $t['category']==='image'?'img':($t['category']==='text'?'txt':($t['category']==='combined'?'combo':'visual')) ?>"><?= strtoupper($t['category']) ?></span>
                        <label style="display:flex;align-items:center;gap:.3rem;font-size:.8rem;cursor:pointer"><input type="checkbox" class="active-edit" <?= $t['active']?'checked':'' ?>> Actif</label>
                    </div>
                </div>
                <textarea class="prompt-edit" style="min-height:70px"><?= htmlspecialchars($t['prompt']) ?></textarea>
                <div class="row" style="margin-top:.5rem">
                    <button class="sm" onclick="saveAdminTool(<?= (int)$t['id'] ?>)">💾 Enregistrer</button>
                    <button class="sm" style="background:linear-gradient(135deg,#a855f7,#fe2c55)" onclick="openImprovePrompt(<?= (int)$t['id'] ?>)">✨ Améliorer avec IA</button>
                    <?php if ($t['is_base']): ?>
                    <button class="sec sm" onclick="resetAdminTool(<?= (int)$t['id'] ?>)">↺ Restaurer</button>
                    <?php else: ?>
                    <button class="sec sm" onclick="deleteAdminTool(<?= (int)$t['id'] ?>)">🗑️ Supprimer</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div id="view-packs" class="hidden"><div class="card"><h2>🧩 Packs d’outils générés par IA</h2><p style="color:var(--muted);font-size:.9rem">Décris un domaine : l’IA crée 4 à 12 outils distincts, de catégories img→txt, txt→txt, image+texte ou txt→img.</p><textarea id="pack-brief" placeholder="Ex. Pack expert mode : stylisme, analyse de campagne, critique photo, tendances, direction artistique, déclinaisons visuelles…"></textarea><div class="row" style="margin-top:.6rem"><button onclick="createPack()" id="btn-create-pack">✨ Créer le pack</button><button class="sec" onclick="loadPacks()">Actualiser</button></div><div id="pack-status"></div><div id="packs-list" style="margin-top:1rem"></div></div><div class="card"><h2>🧠 Optimisation globale</h2><p style="color:var(--muted);font-size:.9rem">Améliore les prompts de tous les outils par lots, sans changer leurs fonctions.</p><button onclick="optimizeAllTools()" id="btn-optimize-all">⚡ Optimiser tous les outils avec IA</button><div id="optimize-status"></div></div></div>

<div id="view-library" class="hidden">
    <div class="card">
        <h2>📚 Ma bibliothèque</h2>
        <div class="row" style="margin-bottom:1rem">
            <select id="lib-type" onchange="loadLibrary(1)" style="max-width:200px"><option value="all">Tout</option><option value="analyses">Analyses</option><option value="images">Images</option></select>
            <label style="font-size:.85rem;color:var(--muted)">Par page :</label>
            <select id="lib-perpage" onchange="loadLibrary(1)" style="max-width:100px"><option value="5">5</option><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option></select>
        </div>
        <div id="lib-grid" class="lib-grid"></div>
        <div id="lib-pagination" class="pagination"></div>
        <div id="lib-empty" style="text-align:center;padding:2rem;color:var(--muted);display:none"><div style="font-size:3rem">📭</div><p>Aucune analyse.</p></div>
    </div>
</div>

<div id="view-keys" class="hidden">
    <div class="card">
        <h2>🔑 Clés API <span class="keys-badge" id="keys-count"><?= $keysCount ?></span></h2>
        <p style="color:var(--muted);font-size:.85rem">Une par ligne. 10 RPM par clé. BDD + cookie (backup).</p>
        <textarea id="keys-input" placeholder="sk-xxx&#10;sk-yyy" style="min-height:140px;font-family:monospace"><?= htmlspecialchars(implode("\n", $apiKeys)) ?></textarea>
        <div class="row" style="margin-top:.6rem"><button onclick="saveKeys()" id="btn-keys">💾 Enregistrer</button></div>
        <div id="keys-msg"></div>
    </div>
</div>

<?php endif; ?>
</div>

<div id="threadModal" class="modal-overlay hidden">
    <div class="modal modal-thread">
        <div class="modal-header"><h3 id="threadTitle">💬 Discussion</h3><button class="close-btn" onclick="closeThread()">×</button></div>
        <div class="modal-body">
            <div class="modal-context-preview" id="threadContext"></div>
            <div class="chat-messages" id="threadMessages" style="max-height:50vh"></div>
        </div>
        <div class="modal-footer" style="flex-direction:column">
            <div class="chat-input-row" style="width:100%">
                <textarea id="threadInput" placeholder="Pose ta question..." rows="2"></textarea>
                <button onclick="sendThreadMsg()" id="btn-thread-send">Envoyer</button>
            </div>
            <div id="threadStatus" style="width:100%"></div>
        </div>
    </div>
</div>

<div id="improveModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header"><h3>✨ Améliorer avec IA</h3><button class="close-btn" onclick="closeImprove()">×</button></div>
        <div class="modal-body">
            <div id="improveLoading" class="loader" style="display:none">⏳ L'IA améliore le prompt...</div>
            <div class="prompt-compare" id="improveCompare" style="display:none">
                <div><h4>Ancien prompt</h4><pre id="oldPrompt"></pre></div>
                <div class="new"><h4>Nouveau prompt</h4><pre id="newPrompt"></pre></div>
            </div>
            <div id="improveMsg"></div>
        </div>
        <div class="modal-footer">
            <button onclick="applyImprovedPrompt()" id="btn-apply-improve">✅ Appliquer</button>
            <button class="sec" onclick="closeImprove()">Annuler</button>
        </div>
    </div>
</div>

<div id="createToolModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header"><h3>✨ Créer un outil via IA</h3><button class="close-btn" onclick="closeCreateTool()">×</button></div>
        <div class="modal-body">
            <p style="color:var(--muted);font-size:.9rem">Décris l'outil que tu veux. L'IA le créera et l'ajoutera.</p>
            <textarea id="createToolInput" placeholder="Ex : un outil qui analyse si mon texte est tendance" style="min-height:100px"></textarea>
            <div id="createToolMsg" style="margin-top:.6rem"></div>
        </div>
        <div class="modal-footer">
            <button onclick="createToolFromChat()" id="btn-create-tool">🚀 Créer</button>
            <button class="sec" onclick="closeCreateTool()">Annuler</button>
        </div>
    </div>
</div>

<script>
async function api(action, data) {
    try {
        const r = await fetch('?action=' + action, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data || {})});
        const text = await r.text();
        try { return JSON.parse(text); } catch(e) { return {success:false, error:'Réponse non-JSON'}; }
    } catch(e) { return {success:false, error:'Erreur réseau : '+e.message}; }
}
function msg(id, text, type) { const el = document.getElementById(id); if (el) el.innerHTML = `<div class="msg ${type||'info'}">${text}</div>`; }
function escapeHtml(t) { return String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function showAuthTab(t) { ['register','login'].forEach(k => { document.getElementById('form-'+k).classList.toggle('hidden', k!==t); document.getElementById('tab-'+k).classList.toggle('active', k===t); }); }
async function doRegister() {
    const url = document.getElementById('reg-url').value.trim(); const pin = document.getElementById('reg-pin').value.trim();
    if (!url || !/^\d{4}$/.test(pin)) { msg('reg-msg','URL et PIN requis','err'); return; }
    const btn = document.getElementById('btn-register'); btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>';
    try { const r = await api('register',{url,pin}); if (r.success) location.reload(); else msg('reg-msg',r.error,'err'); } catch(e){ msg('reg-msg',e.message,'err'); } finally { btn.disabled = false; btn.textContent = 'Créer'; }
}
async function doLogin() {
    const username = document.getElementById('log-user').value.trim(); const pin = document.getElementById('log-pin').value.trim();
    if (!username || !/^\d{4}$/.test(pin)) { msg('log-msg','Identifiant et PIN requis','err'); return; }
    const btn = document.getElementById('btn-login'); btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>';
    try { const r = await api('login',{username,pin}); if (r.success) location.reload(); else msg('log-msg',r.error,'err'); } catch(e){ msg('log-msg',e.message,'err'); } finally { btn.disabled = false; btn.textContent = 'Se connecter'; }
}
async function doLogout() { await api('logout'); location.reload(); }

function switchView(v) {
    ['analyze','bulk','chat','admin','packs','library','keys'].forEach(k => {
        document.getElementById('view-'+k).classList.toggle('hidden', k!==v);
        document.querySelector(`.tabs button[data-view="${k}"]`)?.classList.toggle('active', k===v);
    });
    if (v==='library') loadLibrary(1);
    if (v==='packs') loadPacks();
}

function toggleTool(el) { el.classList.toggle('selected'); updateCount(); }
function selectAllTools(on) { document.querySelectorAll('.tool-card').forEach(c => c.classList.toggle('selected', on)); updateCount(); }
function updateCount() { const n=document.querySelectorAll('.tool-card.selected').length; const el=document.getElementById('selected-count'); if(el)el.textContent=n; }
// Les champs ne désactivent jamais les outils et ne suppriment jamais une sélection.
function updateToolAvailability(){const hasUrl=!!document.getElementById('video-url')?.value.trim();const hasText=!!document.getElementById('user-text')?.value.trim();document.querySelectorAll('.tool-card').forEach(c=>{const cat=c.dataset.category;const ready=cat==='image'?hasUrl:cat==='text'?hasText:cat==='combined'?(hasUrl&&hasText):true;c.classList.remove('disabled');c.title=ready?'Prêt à exécuter':cat==='image'?'Nécessite une URL TikTok':cat==='text'?'Nécessite un texte':cat==='combined'?'Nécessite une URL TikTok et un texte':'Prompt requis';});updateCount();}
document.getElementById('video-url')?.addEventListener('input',updateToolAvailability);document.getElementById('user-text')?.addEventListener('input',updateToolAvailability);

async function loadVideo() {
    const url = document.getElementById('video-url').value.trim(); if (!url) { alert('URL requise'); return; }
    const btn = document.getElementById('btn-load'); btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>';
    try {
        const r = await api('get_subtitles',{url});
        if (r.success) {
            let html = '';
            if (r.thumbnail) html += `<div class="msg info"><b>${escapeHtml(r.title||'')}</b><br><img src="${escapeHtml(r.thumbnail)}" class="thumb-preview"></div>`;
            if (r.subtitles) { document.getElementById('user-text').value = r.subtitles; html += `<div class="msg ok">✅ ${r.subtitles.length} car.</div>`; }
            else html += `<div class="msg warn">⚠️ Pas de sous-titre.</div>`;
            document.getElementById('video-info').innerHTML = html; updateToolAvailability();
        } else msg('video-info', r.error, 'err');
    } catch(e){ msg('video-info',e.message,'err'); } finally { btn.disabled = false; btn.textContent = '🔍 Charger'; }
}

const KEYS_COUNT = <?= $keysCount ?>;
const RPM_INTERVAL_MS = 6500;

async function runAnalyze() {
    const url = document.getElementById('video-url').value.trim();
    const text = document.getElementById('user-text').value.trim();
    const selected = [...document.querySelectorAll('.tool-card.selected')].map(c => c.dataset.slug);
    const turbo = document.getElementById('turbo').checked;
    if (!selected.length) { alert('Sélectionne un outil'); return; }
    if (!url && !text) { alert('Ajoute une URL TikTok ou un texte.'); return; }
    const missing=selected.filter(slug=>{const c=[...document.querySelectorAll('.tool-card')].find(x=>x.dataset.slug===slug);const cat=c?.dataset.category;return(cat==='image'&&!url)||(cat==='text'&&!text)||(cat==='combined'&&(!url||!text));});
    if(missing.length){alert('Entrée manquante pour : '+missing.join(', ')+'.');return;}
    if (KEYS_COUNT === 0) { alert('Configure une clé API'); return; }

    const resultsDiv = document.getElementById('analyze-results'); const msgDiv = document.getElementById('analyze-msg');
    resultsDiv.innerHTML = ''; msgDiv.innerHTML = '';
    const btn = document.getElementById('btn-analyze'); btn.disabled = true;

    const tasks = [];
    if (turbo) { for (const slug of selected) for (let k = 0; k < KEYS_COUNT; k++) tasks.push({slug, keyIdx: k}); }
    else { for (let i = 0; i < selected.length; i++) tasks.push({slug: selected[i], keyIdx: i % KEYS_COUNT}); }

    const batchSize = Math.max(1, KEYS_COUNT);
    const batches = [];
    for (let i = 0; i < tasks.length; i += batchSize) batches.push(tasks.slice(i, i + batchSize));

    for (let b = 0; b < batches.length; b++) {
        const batch = batches[b];
        msgDiv.innerHTML = `<div class="msg info">⏳ Lot ${b+1}/${batches.length}</div>`;
        const responses = await Promise.all(batch.map(t => api('analyze_one', {url, text, tool:t.slug, key_index:t.keyIdx}).catch(e=>({success:false,error:e.message}))));
        responses.forEach((r, i) => {
            const card = document.createElement('div');
            card.className = 'result-card';
            if (r.success) {
                const isImage = !!r.image;
                card.innerHTML = `
                    <div class="card-actions">
                        <button class="ghost sm" onclick="openThread('${isImage?'image':'analysis'}', ${isImage ? r.asset_id : r.analysis_id}, '${escapeHtml((r.label||'').replace(/'/g,"\\'"))}')">💬 Discuter</button>
                    </div>
                    <h4><span class="emoji">${escapeHtml(r.emoji||'✨')}</span> ${escapeHtml(r.label||batch[i].slug)}</h4>
                    <div class="ai-resp">${r.html}</div>`;
            } else {
                card.innerHTML = `<h4>❌ ${escapeHtml(batch[i].slug)}</h4><div class="ai-resp" style="border-left-color:var(--err);color:#fca5a5">${escapeHtml(r.error||'Erreur')}</div>`;
            }
            resultsDiv.appendChild(card);
        });
        if (b < batches.length - 1) { msgDiv.innerHTML += `<div style="font-size:.8rem;color:var(--muted);margin-top:.3rem">⏸️ Pause ${RPM_INTERVAL_MS/1000}s...</div>`; await new Promise(r => setTimeout(r, RPM_INTERVAL_MS)); }
    }
    msgDiv.innerHTML = `<div class="msg ok">✅ Terminé</div>`;
    btn.disabled = false;
}

let bulkAbort = false;
async function runBulk() {
    const urlsRaw = document.getElementById('bulk-urls').value.trim();
    const toolsSel = [...document.getElementById('bulk-tools').selectedOptions].map(o => o.value);
    if (!urlsRaw) { alert('URLs requises'); return; }
    if (!toolsSel.length) { alert('Outils requis'); return; }
    const urls = urlsRaw.split('\n').map(u=>u.trim()).filter(Boolean);
    bulkAbort = false;
    document.getElementById('btn-bulk').disabled = true;
    document.getElementById('btn-bulk-stop').disabled = false;
    document.getElementById('bulk-results').innerHTML = '';
    const statusEl = document.getElementById('bulk-status');

    for (let i=0; i<urls.length; i++) {
        if (bulkAbort) { statusEl.textContent = '⏹ Arrêté.'; break; }
        statusEl.textContent = `⏳ ${i+1}/${urls.length} — ${urls[i].substring(0,50)}`;
        const batchSize = Math.max(1, KEYS_COUNT);
        const urlResults = [];
        for (let j = 0; j < toolsSel.length; j += batchSize) {
            if (bulkAbort) break;
            const batch = toolsSel.slice(j, j + batchSize);
            const responses = await Promise.all(batch.map((slug, idx) => api('analyze_one', {url:urls[i], tool:slug, key_index:idx}).catch(e=>({success:false,error:e.message}))));
            urlResults.push(...responses.map((r, idx) => ({...r, slug: batch[idx]})));
            if (j + batchSize < toolsSel.length) await new Promise(r => setTimeout(r, RPM_INTERVAL_MS));
        }
        const card = document.createElement('div');
        card.className = 'result-card';
        let cardHtml = `<h4>📹 ${escapeHtml(urls[i].substring(0,60))}</h4>`;
        urlResults.forEach(r => {
            if (r.success) {
                const isImg = !!r.image;
                cardHtml += `<div style="margin-top:.5rem"><div class="row" style="justify-content:space-between"><strong>${escapeHtml(r.emoji||'✨')} ${escapeHtml(r.label||r.slug)}</strong><button class="ghost sm" onclick="openThread('${isImg?'image':'analysis'}', ${isImg ? r.asset_id : r.analysis_id}, '${escapeHtml((r.label||'').replace(/'/g,"\\'"))}')">💬</button></div><div class="ai-resp">${r.html}</div></div>`;
            } else cardHtml += `<div class="msg err">❌ ${escapeHtml(r.slug)} : ${escapeHtml(r.error||'')}</div>`;
        });
        card.innerHTML = cardHtml;
        document.getElementById('bulk-results').insertAdjacentHTML('afterbegin', card.outerHTML);
        if (i < urls.length - 1) await new Promise(r => setTimeout(r, RPM_INTERVAL_MS));
    }
    statusEl.textContent = bulkAbort ? '⏹ Arrêté.' : '✅ Terminé.';
    document.getElementById('btn-bulk').disabled = false;
    document.getElementById('btn-bulk-stop').disabled = true;
}

async function createPack(){const description=document.getElementById('pack-brief').value.trim();if(description.length<8){msg('pack-status','Décris le pack plus précisément.','err');return;}const b=document.getElementById('btn-create-pack');b.disabled=true;b.innerHTML='<span class="spinner"></span> Conception IA…';msg('pack-status','L’IA conçoit les outils et leurs prompts…','info');try{const r=await api('create_pack',{description});if(!r.success){msg('pack-status',r.error,'err');return;}msg('pack-status',`Pack « ${escapeHtml(r.pack.name)} » créé : ${r.tools.length} outils. Recharge la page pour actualiser les sélecteurs.`,'ok');loadPacks();}catch(e){msg('pack-status',e.message,'err');}finally{b.disabled=false;b.textContent='✨ Créer le pack';}}
async function loadPacks(){const el=document.getElementById('packs-list');if(!el)return;el.innerHTML='<div class="loader">Chargement…</div>';try{const r=await api('list_packs',{});if(!r.success)throw new Error(r.error);el.innerHTML=r.packs.length?r.packs.map(p=>`<div class="admin-tool"><div class="header"><div class="title">🧩 ${escapeHtml(p.name)}</div><div class="row"><span class="badge custom">${p.tool_count} outils</span><button class="sec sm" onclick="deletePack(${p.id})">Supprimer</button></div></div><div style="color:var(--muted);font-size:.85rem">${escapeHtml(p.description||'')}</div></div>`).join(''):'<div class="msg info">Aucun pack pour le moment.</div>';}catch(e){el.innerHTML=`<div class="msg err">${escapeHtml(e.message)}</div>`;}}
async function deletePack(id){if(!confirm('Supprimer ce pack et ses outils ?'))return;const r=await api('delete_pack',{id});if(r.success)loadPacks();else alert(r.error);}
async function optimizeAllTools(){if(!confirm('Optimiser tous les prompts ? Plusieurs appels API peuvent être nécessaires.'))return;const b=document.getElementById('btn-optimize-all');b.disabled=true;msg('optimize-status','Optimisation par lots…','info');try{const r=await api('optimize_all_tools',{});if(!r.success)msg('optimize-status',r.error,'err');else msg('optimize-status',`${r.optimized} outil(s) optimisé(s). ${r.failed?.length?'Échecs : '+r.failed.join(', '):'Traitement terminé.'}`,'ok');}catch(e){msg('optimize-status',e.message,'err');}finally{b.disabled=false;}}
async function makeBulkPrompts(){const brief=document.getElementById('image-bulk-brief').value.trim();if(!brief){alert('Décris la série.');return;}const b=document.getElementById('btn-make-prompts');b.disabled=true;b.innerHTML='<span class="spinner"></span> Création…';try{const r=await api('bulk_prompt_list',{brief,count:document.getElementById('image-bulk-count').value});if(!r.success)throw new Error(r.error);document.getElementById('image-bulk-prompts').value=r.prompts.join('\n');const st=document.getElementById('image-bulk-status');st.classList.remove('hidden');st.textContent=r.prompts.length+' prompts distincts générés. Modifie-les si nécessaire.';}catch(e){const st=document.getElementById('image-bulk-status');st.classList.remove('hidden');st.textContent=e.message;st.classList.add('err');}finally{b.disabled=false;b.textContent='✨ Générer les prompts avec IA';}}
let imageBulkAbort=false;
async function runImageBulk(){
 const prompts=document.getElementById('image-bulk-prompts').value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
 if(!prompts.length){alert('Ajoute des prompts.');return;} if(prompts.length>30){alert('Maximum 30 images par série.');return;} if(!KEYS_COUNT){alert('Configure tes clés API.');return;}
 imageBulkAbort=false;const b=document.getElementById('btn-image-bulk'),stop=document.getElementById('btn-image-stop'),out=document.getElementById('image-bulk-results'),st=document.getElementById('image-bulk-status');
 b.disabled=true;stop.disabled=false;out.innerHTML='';st.classList.remove('hidden');let ok=0,fail=0;const concurrency=Math.max(1,Math.min(10,KEYS_COUNT));
 try{
  for(let base=0;base<prompts.length;base+=concurrency){if(imageBulkAbort)break;const batch=prompts.slice(base,base+concurrency);st.textContent=`Lot ${Math.floor(base/concurrency)+1}/${Math.ceil(prompts.length/concurrency)} — ${ok+fail}/${prompts.length} terminé(s)`;
   const cards=batch.map((prompt,j)=>{const card=document.createElement('div');card.className='lib-card';card.innerHTML=`<div class="meta">${base+j+1}/${prompts.length}</div><div class="snippet">${escapeHtml(prompt)}</div><div class="loader">Génération…</div>`;out.appendChild(card);return card;});
   const results=await Promise.all(batch.map((prompt,j)=>api('generate_image_one',{prompt,key_index:(base+j)%concurrency}).catch(e=>({success:false,error:e.message}))));
   results.forEach((r,j)=>{if(r.success){ok++;cards[j].innerHTML=`<div class="meta">Image ${base+j+1} · enregistrée</div><img src="${r.image}" loading="lazy" style="width:100%;border-radius:9px"><div class="snippet">${escapeHtml(batch[j])}</div>`;}else{fail++;cards[j].innerHTML=`<div class="msg err">${escapeHtml(r.error||'Erreur inconnue')}</div><div class="snippet">${escapeHtml(batch[j])}</div>`;}});
   if(base+concurrency<prompts.length&&!imageBulkAbort)await new Promise(resolve=>setTimeout(resolve,6500));
  }
 }finally{st.textContent=`${imageBulkAbort?'Arrêt demandé. ':''}Terminé : ${ok} réussite(s), ${fail} échec(s), ${prompts.length} demandé(s).`;b.disabled=false;stop.disabled=true;}
}

async function sendChat() {
    const input = document.getElementById('chat-input'); const message = input.value.trim(); if (!message) return;
    const sendBtn = document.getElementById('btn-chat-send'); const msgs = document.getElementById('chat-messages');
    msgs.insertAdjacentHTML('beforeend', `<div class="chat-msg user">${escapeHtml(message)}</div>`);
    input.value = ''; sendBtn.disabled = true;
    msg('chat-status','<div class="msg info"><span class="spinner"></span>...</div>','info');
    try {
        const r = await api('chat',{message});
        if (!r.success) { msg('chat-status','❌ '+r.error,'err'); return; }
        msgs.insertAdjacentHTML('beforeend', `<div class="chat-msg assistant"><div class="ai-resp">${r.reply}</div></div>`);
        if (r.created_tool) {
            const t = r.created_tool;
            msgs.insertAdjacentHTML('beforeend', `<div class="tool-created-card"><b>✨ ${escapeHtml(t.label)}</b><br><span style="font-size:.8rem">${escapeHtml(t.category)}</span></div>`);
            addToolToGrid(t);
        }
        if (r.generated_image) msgs.insertAdjacentHTML('beforeend', `<div class="chat-msg assistant" style="max-width:100%"><img src="${r.generated_image}" style="max-width:100%;border-radius:8px"></div>`);
        document.getElementById('chat-status').innerHTML = ''; msgs.scrollTop = msgs.scrollHeight;
    } catch(e){ msg('chat-status',e.message,'err'); } finally { sendBtn.disabled = false; }
}
function addToolToGrid(t) {
    const grid = document.getElementById('tools-grid'); if (!grid) return;
    const div = document.createElement('div');
    div.className = 'tool-card is-custom'; div.dataset.slug = t.slug; div.dataset.category = t.category;
    div.onclick = function() { toggleTool(this); };
    div.innerHTML = `<div class="emoji">${escapeHtml(t.emoji||'✨')}</div><div class="info"><div class="label">${escapeHtml(t.label)}</div><div class="cat">${t.category}</div></div><div class="check-indicator">✓</div>`;
    grid.appendChild(div); updateToolAvailability();
}
document.getElementById('chat-input')?.addEventListener('keydown', e => { if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); } });

let currentThread = null;
async function openThread(contextType, contextId, title) {
    if (!contextId) { alert('Analyse non sauvegardée'); return; }
    document.getElementById('threadTitle').textContent = '💬 ' + (title || 'Discussion');
    document.getElementById('threadMessages').innerHTML = '<div class="loader">⏳</div>';
    document.getElementById('threadContext').innerHTML = '';
    document.getElementById('threadModal').classList.remove('hidden');
    try {
        const r = await api('thread_open', {context_type:contextType, context_id:contextId});
        if (!r.success) { document.getElementById('threadMessages').innerHTML = `<div class="msg err">${escapeHtml(r.error)}</div>`; return; }
        currentThread = r.thread_id;
        document.getElementById('threadContext').textContent = 'Contexte : ' + (r.title || '');
        const msgsEl = document.getElementById('threadMessages');
        if (r.messages.length === 0) msgsEl.innerHTML = `<div class="chat-msg assistant"><div class="ai-resp"><p>Pose ta question sur ce contenu.</p></div></div>`;
        else msgsEl.innerHTML = r.messages.map(m => `<div class="chat-msg ${m.role==='user'?'user':'assistant'}">${m.role==='user'?escapeHtml(m.content):`<div class="ai-resp">${m.content}</div>`}</div>`).join('');
        msgsEl.scrollTop = msgsEl.scrollHeight;
    } catch(e) { document.getElementById('threadMessages').innerHTML = `<div class="msg err">${escapeHtml(e.message)}</div>`; }
}
function closeThread() { document.getElementById('threadModal').classList.add('hidden'); currentThread = null; }
async function sendThreadMsg() {
    if (!currentThread) return;
    const input = document.getElementById('threadInput'); const message = input.value.trim(); if (!message) return;
    const msgs = document.getElementById('threadMessages');
    msgs.insertAdjacentHTML('beforeend', `<div class="chat-msg user">${escapeHtml(message)}</div>`);
    input.value = ''; document.getElementById('btn-thread-send').disabled = true;
    document.getElementById('threadStatus').innerHTML = '<div class="msg info"><span class="spinner"></span>...</div>';
    try {
        const r = await api('thread_send',{thread_id:currentThread, message});
        if (r.success) { msgs.insertAdjacentHTML('beforeend', `<div class="chat-msg assistant"><div class="ai-resp">${r.reply}</div></div>`); msgs.scrollTop = msgs.scrollHeight; document.getElementById('threadStatus').innerHTML = ''; }
        else document.getElementById('threadStatus').innerHTML = `<div class="msg err">${escapeHtml(r.error)}</div>`;
    } catch(e) { document.getElementById('threadStatus').innerHTML = `<div class="msg err">${escapeHtml(e.message)}</div>`; }
    finally { document.getElementById('btn-thread-send').disabled = false; }
}
document.getElementById('threadInput')?.addEventListener('keydown', e => { if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); sendThreadMsg(); } });

async function saveAdminTool(id) {
    const c = document.querySelector(`.admin-tool[data-id="${id}"]`);
    const label = c.querySelector('.label-edit').value.trim(); const emoji = c.querySelector('.emoji-edit').value.trim();
    const prompt = c.querySelector('.prompt-edit').value.trim(); const active = c.querySelector('.active-edit').checked ? 1 : 0;
    const r = await api('save_tool',{id,label,emoji,prompt,active});
    if (r.success) { c.classList.toggle('inactive',!active); c.style.boxShadow = '0 0 0 2px rgba(34,197,94,.5)'; setTimeout(()=>c.style.boxShadow='',1000); } else alert(r.error);
}
async function deleteAdminTool(id) {
    if (!confirm('Supprimer ?')) return;
    const r = await api('delete_tool',{id}); if (r.success) document.querySelector(`.admin-tool[data-id="${id}"]`)?.remove();
}
async function resetAdminTool(id) {
    if (!confirm('Restaurer ?')) return;
    const r = await api('reset_tool',{id}); if (r.success) location.reload(); else alert(r.error);
}

let improvingToolId = null;
async function openImprovePrompt(toolId) {
    improvingToolId = toolId;
    document.getElementById('improveModal').classList.remove('hidden');
    document.getElementById('improveLoading').style.display = 'block';
    document.getElementById('improveCompare').style.display = 'none';
    document.getElementById('improveMsg').innerHTML = '';
    try {
        const r = await api('improve_prompt', {tool_id:toolId});
        if (r.success) {
            document.getElementById('oldPrompt').textContent = r.old_prompt;
            document.getElementById('newPrompt').textContent = r.new_prompt;
            document.getElementById('improveLoading').style.display = 'none';
            document.getElementById('improveCompare').style.display = 'grid';
        } else {
            document.getElementById('improveLoading').style.display = 'none';
            document.getElementById('improveMsg').innerHTML = `<div class="msg err">${escapeHtml(r.error)}</div>`;
        }
    } catch(e) {
        document.getElementById('improveLoading').style.display = 'none';
        document.getElementById('improveMsg').innerHTML = `<div class="msg err">${escapeHtml(e.message)}</div>`;
    }
}
function closeImprove() { document.getElementById('improveModal').classList.add('hidden'); improvingToolId = null; }
async function applyImprovedPrompt() {
    if (!improvingToolId) return;
    const newPrompt = document.getElementById('newPrompt').textContent;
    const container = document.querySelector(`.admin-tool[data-id="${improvingToolId}"]`);
    if (!container) { closeImprove(); return; }
    container.querySelector('.prompt-edit').value = newPrompt;
    const label = container.querySelector('.label-edit').value.trim();
    const emoji = container.querySelector('.emoji-edit').value.trim();
    const active = container.querySelector('.active-edit').checked ? 1 : 0;
    const r = await api('save_tool', {id: improvingToolId, label, emoji, prompt: newPrompt, active});
    if (r.success) { container.style.boxShadow = '0 0 0 2px rgba(34,197,94,.5)'; setTimeout(()=>container.style.boxShadow='',1500); closeImprove(); }
    else alert(r.error);
}

function openCreateToolModal() { document.getElementById('createToolModal').classList.remove('hidden'); document.getElementById('createToolInput').value = ''; document.getElementById('createToolMsg').innerHTML = ''; }
function closeCreateTool() { document.getElementById('createToolModal').classList.add('hidden'); }
async function createToolFromChat() {
    const message = document.getElementById('createToolInput').value.trim();
    if (!message) { alert('Décris l\'outil'); return; }
    const btn = document.getElementById('btn-create-tool'); btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>';
    document.getElementById('createToolMsg').innerHTML = '';
    try {
        const r = await api('create_tool_from_chat', {message});
        if (r.success && r.created_tool) {
            document.getElementById('createToolMsg').innerHTML = `<div class="msg ok">✅ Outil créé : ${escapeHtml(r.created_tool.label)}</div>`;
            setTimeout(() => location.reload(), 1200);
        } else if (r.success) {
            document.getElementById('createToolMsg').innerHTML = `<div class="ai-resp">${r.reply}</div>`;
        } else {
            document.getElementById('createToolMsg').innerHTML = `<div class="msg err">${escapeHtml(r.error)}</div>`;
        }
    } catch(e) { document.getElementById('createToolMsg').innerHTML = `<div class="msg err">${escapeHtml(e.message)}</div>`; }
    finally { btn.disabled = false; btn.textContent = '🚀 Créer'; }
}

async function saveKeys() {
    const raw = document.getElementById('keys-input').value;
    const btn = document.getElementById('btn-keys'); btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>';
    try { const r = await api('save_keys',{keys:raw}); if (r.success) { msg('keys-msg',`✅ ${r.count} clé(s)`,'ok'); document.getElementById('keys-count').textContent = r.count; } else msg('keys-msg',r.error,'err'); }
    catch(e){ msg('keys-msg',e.message,'err'); } finally { btn.disabled = false; btn.textContent = '💾 Enregistrer'; }
}

async function loadLibrary(page) {
    const grid = document.getElementById('lib-grid'); const pag = document.getElementById('lib-pagination'); const empty = document.getElementById('lib-empty');
    if (!grid) return;
    const perPage = document.getElementById('lib-perpage')?.value || 10;
    const type = document.getElementById('lib-type')?.value || 'all';
    grid.innerHTML = '<div class="loader">⏳</div>'; pag.innerHTML = ''; empty.style.display = 'none';
    try {
        const r = await api('library',{page: page||1, per_page:parseInt(perPage), type});
        if (!r.success) { grid.innerHTML = `<div class="msg err">${escapeHtml(r.error)}</div>`; return; }
        if (!r.items || r.items.length === 0) { grid.innerHTML = ''; empty.style.display = 'block'; return; }
        grid.innerHTML = r.items.map(item => {
            const isImage = item.kind === 'image';
            const preview = isImage ? escapeHtml(item.title||'') : stripTags(item.content||'').substring(0,120);
            return `<div class="lib-card">
                <div class="lib-actions">
                    <button class="ghost" onclick="openThread('${item.kind}', ${item.id}, '${escapeHtml((item.label||'').replace(/'/g,"\\'"))}')">💬</button>
                </div>
                <div class="meta">${escapeHtml(item.created_at||'')} · ${escapeHtml(item.emoji||'')} ${escapeHtml(item.label||'')}</div>
                <div class="title">${item.url ? `<a href="${escapeHtml(item.url)}" target="_blank" style="color:var(--pink)">${escapeHtml(item.title||'Voir')}</a>` : escapeHtml(item.title||'')}</div>
                <div class="snippet">${preview}</div>
                ${item.thumbnail ? `<img src="${escapeHtml(item.thumbnail)}" loading="lazy">` : ''}
                ${isImage ? `<button class="sec sm" style="margin-top:.5rem" onclick="showLibraryImage(this,${item.id})">Afficher l’image</button>` : ''}
            </div>`;
        }).join('');
        let ph = '';
        if (r.pages > 1) {
            for (let p=1; p<=r.pages; p++) {
                if (p===1 || p===r.pages || Math.abs(p-r.page)<=2) ph += `<button class="${p===r.page?'active':''}" onclick="loadLibrary(${p})">${p}</button>`;
                else if (Math.abs(p-r.page)===3) ph += `<span style="color:var(--muted);padding:.4rem">…</span>`;
            }
        }
        pag.innerHTML = ph;
    } catch(e) { grid.innerHTML = `<div class="msg err">${escapeHtml(e.message)}</div>`; }
}
async function showLibraryImage(button,id){button.disabled=true;button.textContent='Chargement…';try{const r=await api('asset_image',{id});if(!r.success)throw new Error(r.error);const img=document.createElement('img');img.src=r.image;img.loading='lazy';img.style.cssText='width:100%;max-height:420px;object-fit:contain;border-radius:9px;margin-top:.5rem';button.replaceWith(img);}catch(e){button.disabled=false;button.textContent='Réessayer';alert(e.message);}}
function stripTags(html) { const d = document.createElement('div'); d.innerHTML = html||''; return d.textContent||''; }

document.querySelectorAll('#reg-pin, #log-pin').forEach(el => { el.addEventListener('input', e => e.target.value = e.target.value.replace(/\D/g,'').slice(0,4)); });
updateToolAvailability();
</script>
</body>
</html>
