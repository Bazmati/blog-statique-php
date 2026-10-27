<?php
/**
 * ============================================================
 *  Basile Blog — Générateur de blog statique en PHP pur
 *  Aucune dépendance. Aucune mise à jour. Ton code, ton site.
 * ============================================================
 *
 *  STRUCTURE DU PROJET :
 *
 *  blog/
 *  ├── build.php          <-- ce fichier (le générateur complet)
 *  ├── content/           <-- tes articles, un fichier .md par article
 *  │   ├── 2026-10-27-de-l-atelier-au-code.md
 *  │   └── 2026-11-10-ce-que-l-industrie-m-a-appris.md
 *  ├── assets/            <-- dépose ici tes images brutes (jpg/png/webp)
 *  │   ├── avatar.jpg          -> générée en dist/assets/avatar.webp
 *  │   └── atelier.jpg         -> générée en dist/assets/atelier.webp
 *  └── dist/              <-- généré automatiquement, à rsync sur le serveur
 *
 *  TRAITEMENT AUTOMATIQUE DES IMAGES :
 *  - Redimension à 1500 px de large max
 *  - Conversion en WebP (qualité 82) sans renommage : atelier.jpg -> atelier.webp
 *  - Dans tes .md, tu références TOUJOURS le nom d'origine (atelier.jpg) :
 *    le HTML généré pointe automatiquement vers /assets/atelier.webp.
 *  - Les .svg sont copiés tels quels (logos).
 *  - Nécessite l'extension PHP GD avec support WebP (php -m | grep gd ;
 *    sur Ubuntu : sudo apt install php-gd).
 *
 *  FRONTMATTER d'un article (en haut du .md, entre deux lignes ---) :
 *
 *  ---
 *  title: De l'atelier au code
 *  date: 2026-10-27
 *  description: Pourquoi j'ai quitté la maintenance industrielle pour le développement.
 *  category: Reconversion
 *  tags: industrie, php, carriere
 *  image: assets/atelier.jpg    <-- image de couverture (optionnelle mais recommandée)
 *  image_alt: Mon poste de soudage à l'atelier   <-- alt de l'image de couverture
 *  ---
 *
 *  Le corps de l'article est du Markdown, images incluses :
 *  ![Texte alternatif de l'image](assets/mon-image.jpg)
 *
 *  CONVENTION POUR LES IMAGES : référence-les toujours comme si tu étais
 *  à la racine du site (assets/nom.jpg). Le générateur réécrit
 *  automatiquement le chemin selon la page (accueil ou article) et
 *  convertit en .webp. Les pages utilisent des chemins RELATIFS :
 *  le site fonctionne donc en local, en preview, et en production.
 *
 *  UTILISATION :
 *      php build.php                     (génère dist/)
 *      rsync -rav --delete dist/ user@serveur:/var/www/basile/
 *
 *  SORTIE GÉNÉRÉE :
 *      dist/index.html          liste des articles
 *      dist/article/<slug>/     une page par article
 *      dist/sitemap.xml         pour Google Search Console
 *      dist/feed.xml            flux RSS
 *      dist/robots.txt
 * ============================================================
 */

// ------------------------- CONFIGURATION -------------------------
// C'est le seul endroit à modifier.

$CONFIG = [
    'site_name'    => 'Basile Malin — Développeur',
    'site_url'     => 'https://basile-malin.fr',   // à adapter au domaine acheté
    'author'       => 'Basile Malin',
    'description'  => 'Blog de Basile Malin : développement web, reconversion depuis l\'industrie, PHP, Symfony, systèmes.',
    // Texte d'accroche affiché sur la première page, au-dessus de la liste
    'tagline'     => "Après douze ans de terrain industriel, j'ai repris les études pour devenir développeur. " .
                     "Ici, je raconte ce que je code, ce que j'administre et ce que je dépanne — souvent les trois à la fois.",
    'cv_url'       => 'https://bazmati.github.io/basile-malin-cv-2026/',
    'github_url'    => 'https://github.com/bazmati',
    'email'         => 'basilemalin@gmx.fr',
    // Mentions légales (LCEN) : éditeur et hébergeur du site auto-hébergé
    'host_name'    => 'Basile Malin — auto-hébergé',
    'content_dir'  => __DIR__ . '/content',
    'output_dir'   => __DIR__ . '/dist',
];

// Slug propre : minuscules, sans accents, espaces -> tirets.
// "Support IT N1" -> "support-it-n1"
function slugify(string $s): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s;
}

// ------------------------- FRONTMATTER ---------------------------

function parse_article(string $file, array $config): ?array
{
    $raw = file_get_contents($file);
    if ($raw === false) return null;

    $parts = preg_split('/^\s*---\s*$/m', $raw, 3);
    // parts: [avant, frontmatter, corps]
    if (count($parts) < 3) {
        fwrite(STDERR, "AVERTISSEMENT : $file n'a pas de frontmatter, ignoré.\n");
        return null;
    }

    $meta = [];
    foreach (explode("\n", trim($parts[1])) as $line) {
        if ($line === '' || !str_contains($line, ':')) continue;
        [$key, $value] = explode(':', $line, 2);
        $meta[trim(strtolower($key))] = trim($value);
    }

    // Belles URLs : le nom de fichier peut préfixer la date (2026-10-27-mon-article.md)
    // -> l'URL devient simplement /article/mon-article/, la date reste dans le frontmatter.
    $slug = basename($file, '.md');
    $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $slug) ?? $slug;
    $date = $meta['date'] ?? date('Y-m-d');

    return [
        'slug'        => $slug,
        'title'       => $meta['title'] ?? $slug,
        'date'        => $date,
        'date_fr'     => DateTime::createFromFormat('Y-m-d', $date)
                             ? (new DateTime($date))->format('d/m/Y') : $date,
        'description'  => $meta['description'] ?? mb_substr(strip_tags($parts[2]), 0, 155),
        'tags'        => array_map('trim', array_filter(explode(',', $meta['tags'] ?? ''))),
        'category'    => $meta['category'] ?? null,
        'category_slug' => $meta['category'] ? slugify($meta['category']) : null,
        // Image de couverture : accepte "atelier.jpg" ou "assets/atelier.jpg"
        'image'       => isset($meta['image']) ? basename($meta['image']) : null,
        'image_alt'   => $meta['image_alt'] ?? ($meta['title'] ?? ''),
        'url'         => $config['site_url'] . '/article/' . $slug . '/',
        'body_html'   => markdown_to_html(trim($parts[2])),
    ];
}

// ------------------- MINI CONVERTISSEUR MARKDOWN ------------------
// Couvre ce qu'un article de blog utilise : titres, gras, italique,
// liens, listes, blocs de code, paragraphes. (Détail volontaire :
// pas de dépendance externe. Si un jour tu veux plus, remplace cette
// fonction par Parsedown — c'est un seul fichier à déposer à côté.)

function markdown_to_html(string $md): string
{
    $md = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
    $in_code = false; $in_list = false; $in_quote = false;
    $table_rows = []; $in_table = false;
    $html = '';

    // Rendu d'un tableau Markdown accumulé
    $flush_table = function () use (&$table_rows, &$in_table, &$html) {
        if (count($table_rows) < 2) { $table_rows = []; $in_table = false; return; }
        $html .= "<table>\n<thead><tr>";
        foreach (explode('|', trim($table_rows[0], '|')) as $th) $html .= '<th>' . inline(trim($th)) . '</th>';
        $html .= "</tr></thead>\n<tbody>";
        for ($i = 1; $i < count($table_rows); $i++) {
            $html .= '<tr>';
            foreach (explode('|', trim($table_rows[$i], '|')) as $td) $html .= '<td>' . inline(trim($td)) . '</td>';
            $html .= '</tr>';
        }
        $html .= "</tbody></table>\n";
        $table_rows = []; $in_table = false;
    };

    foreach (preg_split('/\r\n|\n|\r/', $md) as $line) {
        // Blocs de code ``` ... ```
        if (str_starts_with($line, '```')) {
            if ($in_table) $flush_table();
            if ($in_quote) { $html .= "</blockquote>\n"; $in_quote = false; }
            $html .= $in_code ? "</code></pre>\n" : '<pre><code>';
            $in_code = !$in_code;
            continue;
        }
        if ($in_code) { $html .= $line . "\n"; continue; }

        // Tableaux Markdown : | a | b |  (2e ligne = séparateur |---|---|)
        if (str_starts_with(trim($line), '|')) {
            if ($in_quote) { $html .= "</blockquote>\n"; $in_quote = false; }
            $cells = trim(trim($line), '|');
            if (preg_match('/^\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*$/', $cells)) continue; // ligne séparateur
            $table_rows[] = $cells; $in_table = true;
            continue;
        }
        if ($in_table) $flush_table();

        // Citations : > texte
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            if (!$in_quote) { $html .= "<blockquote>\n"; $in_quote = true; }
            $html .= '<p>' . inline($m[1]) . "</p>\n";
            continue;
        }
        if ($in_quote) { $html .= "</blockquote>\n"; $in_quote = false; }

        // Listes
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
            if (!$in_list) { $html .= "<ul>\n"; $in_list = true; }
            $html .= '<li>' . inline($m[1]) . "</li>\n";
            continue;
        }
        if ($in_list) { $html .= "</ul>\n"; $in_list = false; }

        // Titres
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $lvl = strlen($m[1]);
            $html .= "<h$lvl>" . inline($m[2]) . "</h$lvl>\n";
            continue;
        }
        // Paragraphe (les lignes vides séparant)
        if (trim($line) === '') { $html .= "\n"; continue; }

        $html .= '<p>' . inline($line) . "</p>\n";
    }
    if ($in_table) $flush_table();
    if ($in_quote) $html .= "</blockquote>\n";
    if ($in_list) $html .= "</ul>\n";
    if ($in_code) $html .= "</code></pre>\n";
    return $html;
}

// Inline : gras, italique, liens
function inline(string $s): string
{
    // Images : ![alt](url) — avant les liens pour ne pas être avalées par eux
    $s = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" loading="lazy">', $s);
    $s = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $s);
    return $s;
}

// ------------------------- TEMPLATES -----------------------------

// Feuille de style unique, écrite dans dist/assets/style.css et liée
// par toutes les pages (mise en cache navigateur + correctifs a11y :
// contrastes WCAG AA, focus clavier visible, longueur de lecture 70ch).
function site_css(): string
{
    return <<<'CSS'
        :root { --accent:#1e40af; --accent-dark:#1a368f; --ink:#1c1e21; --bg:#fcfcfa; --muted:#565b63; }
        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { font:17px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif; color:var(--ink); background:var(--bg); margin:0;
               /* Sticky footer : le contenu occupe au moins l'écran, le footer reste en bas */
               min-height:100vh; display:flex; flex-direction:column; }
        .wrap { flex:1; width:100%; max-width:1100px; margin:0 auto; padding:1.5rem 2rem; }
        /* Header sticky : la nav reste toujours accessible, mobile inclus */
        header { position:sticky; top:0; z-index:10; background:var(--bg);
                 border-bottom:2px solid var(--accent); margin-bottom:2rem; }
        header h1 a { color:var(--ink); text-decoration:none; }
        header h1 a span { color:var(--accent); }
        .logo { vertical-align:-4px; margin-right:.4rem; border-radius:6px; }
        nav a { color:var(--muted); text-decoration:none; margin-right:1rem; font-size:.95rem; }
        nav a:hover { color:var(--accent); }
        /* Lisibilité : 65-75 caractères par ligne dans la colonne principale */
        main { max-width:70ch; }
        /* Accroche de la page d'accueil */
        .hero-intro { font-size:1.05rem; line-height:1.8; margin:0 0 2rem; padding:1.2rem 1.5rem;
                      border-left:3px solid var(--accent); background:#f4f6fb; border-radius:0 8px 8px 0; }
        article h2 { margin-top:2rem; }
        .meta { color:var(--muted); font-size:.9rem; }
        .tag { display:inline-block; background:#eee; border-radius:6px; padding:0 .5rem; font-size:.8rem; }
        a.tag { color:inherit; text-decoration:none; }
        a.tag:hover { background:var(--accent); color:#fff; }
        .tag.cat { background:#e8edf8; color:#1a368f; }
        a.tag.cat:hover { background:var(--accent); }
        pre { background:#f0f2f4; padding:1rem; border-radius:8px; overflow-x:auto; font-size:.9em; }
        code { background:#f0f2f4; padding:.1rem .3rem; border-radius:4px; }
        pre code { padding:0; }
        blockquote { border-left:3px solid var(--accent); margin:1.2rem 0; padding:.2rem 0 .2rem 1rem; color:var(--muted); }
        table { border-collapse:collapse; margin:1.2rem 0; width:100%; }
        th, td { border:1px solid #ddd; padding:.4rem .6rem; text-align:left; }
        th { background:#f0f2f4; }
        footer { border-top:1px solid #ddd; margin-top:3rem; padding:1rem 0; color:var(--muted); font-size:.85rem; }
        .cards { display:grid; grid-template-columns:repeat(2, 1fr); gap:1rem; }
        .cards a.card { display:flex; gap:1rem; align-items:flex-start; color:inherit; text-decoration:none;
                        border:1px solid #e2e2e2; border-radius:10px; padding:1rem; background:#fff; }
        .cards a.card:hover { border-color:var(--accent); }
        .cards h2 { margin:.2rem 0 .3rem; font-size:1.05rem; }
        .card img.cover { order:2; width:120px; height:90px; object-fit:cover; border-radius:8px; flex:none; }
        .card .card-body { flex:1; min-width:0; }
        article img { max-width:100%; height:auto; border-radius:8px; display:block; margin:1.2rem auto; }
        /* Réservation d'espace -> pas de saut de texte au chargement (CLS) */
        .hero { width:100%; aspect-ratio:16/9; object-fit:cover; }
        .layout { display:grid; grid-template-columns:1fr 280px; gap:2.5rem; align-items:start; }
        .sidebar { border-left:1px solid #e6e6e6; padding-left:1.5rem; font-size:.92rem; }
        .sidebar h3 { font-size:.85rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin:1.4rem 0 .5rem; }
        .sidebar ul { list-style:none; padding:0; margin:0; }
        .sidebar li { margin:.3rem 0; }
        .sidebar a { color:inherit; text-decoration:none; }
        .sidebar a:hover { color:var(--accent); }
        .sidebar .tag { margin:0 .25rem .25rem 0; }
        .filter-chip { display:inline-block; background:var(--accent); color:#fff; border-radius:6px; padding:.15rem .7rem; font-size:.85rem; text-decoration:none; }
        .filter-chip:hover { background:var(--accent-dark); }
        .crumbs { font-size:.85rem; color:var(--muted); margin-bottom:1.2rem; }
        .crumbs a { color:inherit; text-decoration:none; }
        .crumbs a:hover { color:var(--accent); }
        .crumbs .sep { margin:0 .35rem; opacity:.5; }
        .crumbs span:last-child { color:var(--ink); }
        /* Accessibilité clavier : focus visible */
        a:focus-visible, a.tag:focus-visible, .card:focus-visible { outline:2px solid var(--accent); outline-offset:2px; border-radius:4px; }
        /* Burger mobile de la sidebar : <details> natif, replié sur mobile, déroulé sur desktop */
        .sb-menu summary { cursor:pointer; font-size:.85rem; text-transform:uppercase; letter-spacing:.05em;
                           color:var(--accent); font-weight:600; padding:.4rem 0; }
        .sb-menu summary::marker { content:"▸ "; color:var(--accent); }
        .sb-menu[open] summary::marker { content:"▾ "; }
        @media (min-width:801px) { .sb-menu summary { display:none; } }
        /* Pagination */
        .pagination { display:flex; gap:1rem; align-items:center; justify-content:space-between; margin:2rem 0; font-size:.9rem; }
        .pagination a { text-decoration:none; color:var(--accent); font-weight:600; }
        .pagination a:hover { text-decoration:underline; }
        .pagination .disabled { color:#bbb; }
        @media (max-width:800px) {
            .layout { grid-template-columns:1fr; }
            .sidebar { border-left:none; padding-left:0; border-top:1px solid #ddd; padding-top:1rem; }
            .cards { grid-template-columns:1fr; }
            .wrap { padding:1.5rem; }
        }
CSS;
}

function layout(string $title, string $description, string $body, array $config, ?string $og_image = null, string $root = '', string $sidebar = '', array $breadcrumbs = []): string
{
    $url = $config['site_url'];
    $cv = $config['cv_url'];
    $gh = $config['github_url'];
    $og_image ??= "$url/assets/avatar.jpg";

    // Données structurées JSON-LD : profil Person (global)
    $jsonld_person = json_encode([
        '@context'  => 'https://schema.org',
        '@type'     => 'Person',
        'name'      => $config['author'],
        'url'       => $url,
        'email'     => 'mailto:' . $config['email'],
        'jobTitle'  => 'Développeur d\'applications',
        'sameAs'    => [$gh, $cv],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Fil d'Ariane : inséré en haut de la colonne principale
    if ($breadcrumbs) {
        $body = crumbs_html($breadcrumbs, $root) . $body;
    }
    $crumb_script = $breadcrumbs ? '<script type="application/ld+json">' . crumbs_jsonld($breadcrumbs, $url) . '</script>' : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>$title</title>
    <meta name="description" content="$description">
    <link rel="canonical" href="$url/">
    <meta property="og:type" content="website">
    <meta property="og:title" content="$title">
    <meta property="og:description" content="$description">
    <meta property="og:image" content="$og_image">
    <meta name="twitter:card" content="summary">
    <link rel="alternate" type="application/rss+xml" href="{$root}feed.xml" title="{$config['site_name']}">
    <script type="application/ld+json">$jsonld_person</script>
    $crumb_script
    <link rel="icon" href="{$root}assets/{$config['favicon']}" type="image/webp">
    <link rel="stylesheet" href="{$root}assets/style.css">
</head>
<body>
<div class="wrap">
    <header>
        <h1><a href="{$config['site_url']}/"><img class="logo" src="{$root}assets/favicon.webp" alt="Logo — Basile Malin" width="80" height="60"> Basile <span>Malin</span></a></h1>
        <nav>
            <a href="$root">Blog</a>
            <a href="$cv" rel="noopener">CV</a>
            <a href="$gh" rel="noopener">GitHub</a>
            <a href="{$root}feed.xml">RSS</a>
        </nav>
    </header>
    <div class="layout">
    <main>
    $body
    </main>
    <aside class="sidebar">
    $sidebar
    </aside>
    </div>
    <script>
    // Seul JS du site : replie la sidebar en burger sur mobile (état par défaut).
    if (matchMedia('(max-width:800px)').matches) {
        document.querySelectorAll('.sb-menu').forEach(function (d) { d.open = false; });
    }
    </script>
    <footer>
        <nav style="margin-bottom:.6rem">
            <a href="{$root}">Blog</a> ·
            <a href="{$root}mentions-legales/">Mentions légales</a> ·
            <a href="{$root}feed.xml">RSS</a> ·
            <a href="$cv" rel="noopener">CV</a> ·
            <a href="$gh" rel="noopener">GitHub</a>
        </nav>
        {$config['author']} — généré statiquement en PHP, auto-hébergé.<br>
        Contact : <a href="mailto:{$config['email']}">{$config['email']}</a>
    </footer>
</div>
</body>
</html>
HTML;
}

// ------------------- TRAITEMENT DES IMAGES -----------------------
// Redimensionne (1500 px max) et convertit en WebP sans renommage.
// Retourne la table de correspondance : "atelier.jpg" => "atelier.webp"

define('IMG_MAX_WIDTH', 1500);
define('IMG_WEBP_QUALITY', 82);

function process_assets(string $srcDir, string $dstDir): array
{
    $map = [];
    if (!is_dir($srcDir)) return $map;
    if (!is_dir($dstDir)) @mkdir($dstDir, 0775, true);
    if (!function_exists('imagewebp')) {
        fwrite(STDERR, "AVERTISSEMENT : GD/WebP absent (php-gd), images copiées sans conversion.\n");
        foreach (scandir($srcDir) ?: [] as $f) {
            if (is_file("$srcDir/$f")) { copy("$srcDir/$f", "$dstDir/$f"); }
        }
        return $map;
    }

    foreach (scandir($srcDir) ?: [] as $f) {
        $path = "$srcDir/$f";
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

        // SVG : copie directe, rien à optimiser
        if ($ext === 'svg') { copy($path, "$dstDir/$f"); continue; }

        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) continue;

        $src = match ($ext) {
            'png'    => imagecreatefrompng($path),
            'webp'   => imagecreatefromwebp($path),
            default  => imagecreatefromjpeg($path),
        };
        if (!$src) { fwrite(STDERR, "AVERTISSEMENT : image illisible : $f\n"); continue; }

        // Redimension si trop large
        $w = imagesx($src); $h = imagesy($src);
        if ($w > IMG_MAX_WIDTH) {
            $nw = IMG_MAX_WIDTH;
            $nh = (int) round($h * $nw / $w);
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        $name = pathinfo($f, PATHINFO_FILENAME);
        $webp = "$dstDir/$name.webp";
        imagewebp($src, $webp, IMG_WEBP_QUALITY);
        imagedestroy($src);

        $map[$f] = "$name.webp";
    }
    return $map;
}

// Réécrit les URLs d'images du Markdown en jetons neutres : le chemin
// final (relatif, adapté à la profondeur de la page) est résolu au
// rendu. Convention : référencer "assets/nom.jpg" depuis le Markdown.
function rewrite_asset_urls(string $html, array $map): string
{
    foreach ($map as $orig => $webp) {
        $html = str_replace("assets/$orig", "%%ASSET:$webp%%", $html);
    }
    return $html;
}

// Fil d'Ariane : HTML visible + données structurées BreadcrumbList.
// $items : [['label' =>, 'rel' => chemin relatif, 'abs' => URL absolue]]
// Le dernier élément (page courante) est un texte simple, pas un lien.
function crumbs_html(array $items, string $root): string
{
    $html = '<nav class="crumbs"><a href="' . $root . '">Accueil</a>';
    $last = array_pop($items);
    foreach ($items as $it) {
        $html .= '<span class="sep">›</span><a href="' . $it['rel'] . '">' . htmlspecialchars($it['label']) . '</a>';
    }
    $html .= '<span class="sep">›</span><span>' . htmlspecialchars($last['label']) . '</span></nav>';
    return $html;
}

function crumbs_jsonld(array $items, string $siteUrl): string
{
    $list = [[
        '@type' => 'ListItem', 'position' => 1,
        'name' => 'Accueil', 'item' => $siteUrl . '/',
    ]];
    $pos = 2;
    foreach ($items as $it) {
        $entry = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $it['label']];
        if (!empty($it['abs'])) $entry['item'] = $it['abs'];
        $list[] = $entry;
    }
    return json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $list,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// --------------------------- BUILD --------------------------------

$out = $CONFIG['output_dir'];
@mkdir("$out/article", 0775, true);

// Les images sont traitées AVANT de parser les articles : la table de
// correspondance sert ensuite à réécrire les URLs dans le HTML généré.
$assetMap = process_assets(__DIR__ . '/assets', "$out/assets");

/* ---- Fichier du blog externe + favicon ---- */
// mkdir systématique : dist/assets/ doit exister même si assets/ est vide
if (!is_dir("$out/assets")) @mkdir("$out/assets", 0775, true);
file_put_contents("$out/assets/style.css", site_css());
// Favicon : favicon.png déposé dans assets/ (le logo "chevron-étincelle"
// généré par IA, téléchargé depuis la conversation). Copié par process_assets.
// Si absent, le navigateur affiche l'icône par défaut — pas de plantage.


$articles = [];
foreach (glob($CONFIG['content_dir'] . '/*.md') ?: [] as $file) {
    $a = parse_article($file, $CONFIG);
    if ($a) $articles[] = $a;
}
// Du plus récent au plus ancien
usort($articles, fn($a, $b) => strcmp($b['date'], $a['date']));

if (!$articles) {
    fwrite(STDERR, "Aucun article trouvé dans {$CONFIG['content_dir']}. Ajoute un .md avec frontmatter.\n");
    exit(1);
}

$out = $CONFIG['output_dir'];
@mkdir("$out/article", 0775, true);

// Réécriture des images : le frontmatter garde le nom final (webp),
// le corps Markdown est converti en jetons résolus au rendu.
foreach ($articles as &$a) {
    $a['image'] = isset($assetMap[$a['image']]) ? $assetMap[$a['image']] : $a['image'];
    $a['body_html'] = rewrite_asset_urls($a['body_html'], $assetMap);
}
unset($a);

// Favicon : process_assets la convertit en .webp — on référence le nom converti
$CONFIG['favicon'] = $assetMap['favicon.png'] ?? 'favicon.png';

// Regroupement catégories / tags : calculé UNE fois ici, utilisé par
// l'accueil, les pages articles, les pages de liste et le sitemap.
// slug => ['label' =>, 'articles' => [...]]
$categories = []; $allTags = [];
foreach ($articles as $a) {
    if ($a['category']) $categories[$a['category_slug']] = ['label' => $a['category'], 'articles' => []];
    foreach ($a['tags'] as $t) $allTags[slugify($t)] = ['label' => $t, 'articles' => []];
}
foreach ($articles as $a) {
    if ($a['category']) $categories[$a['category_slug']]['articles'][] = $a;
    foreach ($a['tags'] as $t) $allTags[slugify($t)]['articles'][] = $a;
}

// Barre latérale : 3 derniers articles, catégories, tags.
// $p = préfixe relatif selon la profondeur de la page ('' ou '../../').
// $showBack = affiche le lien "Tous les articles" (pages articles uniquement).
$make_sidebar = function (string $p, bool $showBack = false) use ($articles, $categories, $allTags): string {
    // Identique au lien "Tous les articles" du bas d'article
    $html = $showBack
        ? '<p><a href="' . $p . '">&larr; Tous les articles</a></p>'
        : '';
    $html .= '<h3>Derniers articles</h3><ul>';
    foreach (array_slice($articles, 0, 3) as $a) {
        $html .= '<li><a href="' . $p . 'article/' . $a['slug'] . '/">' . htmlspecialchars($a['title']) . '</a></li>';
    }
    $html .= '</ul>';
    if ($categories) {
        $html .= '<h3>Catégories</h3><ul>';
        foreach ($categories as $slug => $data) {
            $html .= '<li><a href="' . $p . 'categorie/' . $slug . '/">' . htmlspecialchars($data['label'])
                   . '</a> <span class="meta">(' . count($data['articles']) . ')</span></li>';
        }
        $html .= '</ul>';
    }
    if ($allTags) {
        $html .= '<h3>Tags</h3><p>';
        foreach ($allTags as $slug => $data) {
            $html .= '<a class="tag" href="' . $p . 'tag/' . $slug . '/">' . htmlspecialchars($data['label']) . '</a>';
        }
        $html .= '</p>';
    }
    // Burger mobile : <details> natif — replié sur mobile (voir JS du layout),
    // toujours déroulé sur desktop (summary masqué en CSS).
    return '<details class="sb-menu" open><summary>Catégories, tags & derniers articles</summary>'
         . $html . '</details>';
};

/* ---- Accueil + pagination (10 articles par page) ---- */
// Cartes d'articles réutilisées par toutes les listes. $p = préfixe relatif.
$render_cards = function (array $list, string $p) {
    $cards = '';
    foreach ($list as $a) {
        $cover = $a['image']
            ? '<img class="cover" src="' . $p . 'assets/' . htmlspecialchars($a['image']) . '" alt="' . htmlspecialchars($a['image_alt']) . '" loading="lazy">'
            : '';
        $tagsHtml = implode(' ', array_map(fn($t) => '<span class="tag">' . htmlspecialchars($t) . '</span>', $a['tags']));
        $catHtml = $a['category'] ? '<span class="tag cat">' . htmlspecialchars($a['category']) . '</span> ' : '';
        $cards .= '<a class="card" href="' . $p . 'article/' . $a['slug'] . '/">'
            . '<span class="card-body">'
            . '<div class="meta"><time datetime="' . $a['date'] . '">' . $a['date_fr'] . '</time> · ' . $catHtml . $tagsHtml . '</div>'
            . '<h2>' . htmlspecialchars($a['title']) . '</h2>'
            . '<p>' . htmlspecialchars($a['description']) . '</p>'
            . '</span>'
            . $cover
            . '</a>';
    }
    return $cards;
};

$pages = array_chunk($articles, 10); // 10 articles par page
$totalPages = count($pages);
foreach ($pages as $i => $chunk) {
    $n = $i + 1;                 // numéro de page (1 = accueil)
    $p = $n === 1 ? '' : '../../'; // préfixe relatif selon la profondeur

    $nav = '';
    if ($totalPages > 1) {
        $prev = $n === 2 ? '' : 'page/' . ($n - 1) . '/';
        $nav = '<nav class="pagination">'
            . ($n > 1 ? '<a href="' . $p . $prev . '">&larr; Plus récents</a>' : '<span class="disabled">&larr; Plus récents</span>')
            . '<span>Page ' . $n . ' sur ' . $totalPages . '</span>'
            . ($n < $totalPages ? '<a href="' . $p . 'page/' . ($n + 1) . '/">Plus anciens &rarr;</a>' : '<span class="disabled">Plus anciens &rarr;</span>')
            . '</nav>';
    }

    $hero = $n === 1 ? '<p class="hero-intro">' . htmlspecialchars($CONFIG['tagline']) . '</p>' : '';

    $pageHtml = layout(
        $n === 1 ? $CONFIG['site_name'] : $CONFIG['site_name'] . ' — page ' . $n,
        $CONFIG['description'],
        $hero . '<section class="cards">' . $render_cards($chunk, $p) . '</section>' . $nav,
        $CONFIG,
        $CONFIG['site_url'] . '/assets/' . ($assetMap['avatar.jpg'] ?? 'avatar.jpg'),
        $p,
        $make_sidebar($p)
    );

    if ($n === 1) {
        file_put_contents("$out/index.html", $pageHtml);
    } else {
        @mkdir("$out/page/$n", 0775, true);
        file_put_contents("$out/page/$n/index.html", $pageHtml);
    }
}

/* ---- Pages articles ---- */
foreach ($articles as $a) {
    $tags = implode(' ', array_map(
        fn($t) => '<a class="tag" href="../../tag/' . slugify($t) . '/">' . htmlspecialchars($t) . '</a>',
        $a['tags']
    ));
    $cat = $a['category']
        ? '<a class="tag cat" href="../../categorie/' . $a['category_slug'] . '/">' . htmlspecialchars($a['category']) . '</a>'
        : '';

    $jsonld_article = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $a['title'],
        'description' => $a['description'],
        'datePublished' => $a['date'],
        'author' => ['@type' => 'Person', 'name' => $CONFIG['author']],
        'mainEntityOfPage' => $a['url'],
        'image' => $a['image'] ? $CONFIG['site_url'] . $a['image'] : null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $body = '<article>'
        . '<h1>' . htmlspecialchars($a['title']) . '</h1>'
        . '<p class="meta"><time datetime="' . $a['date'] . '">' . $a['date_fr'] . '</time></p>'
        . ($a['image']
            ? '<img class="hero" src="../../assets/' . htmlspecialchars($a['image']) . '" alt="' . htmlspecialchars($a['image_alt']) . '">'
            : '')
        . str_replace('%%ASSET:', '../../assets/', $a['body_html'])
        . '<p>' . $cat . ' ' . $tags . '</p>'
        . '<p><a href="../../">&larr; Tous les articles</a></p>'
        . '</article>'
        . '<script type="application/ld+json">' . $jsonld_article . '</script>';

    $bc = [['label' => 'Accueil', 'rel' => '../../', 'abs' => $CONFIG['site_url'] . '/']];
    if ($a['category']) {
        $bc[] = ['label' => $a['category'],
                 'rel' => '../../categorie/' . $a['category_slug'] . '/',
                 'abs' => $CONFIG['site_url'] . '/categorie/' . $a['category_slug'] . '/'];
    }
    $bc[] = ['label' => $a['title'], 'rel' => '', 'abs' => $a['url']];

    $page = layout(
        $a['title'] . ' — ' . $CONFIG['site_name'],
        $a['description'],
        $body,
        $CONFIG,
        $a['image'] ? $CONFIG['site_url'] . '/assets/' . $a['image'] : null,
        '../../',
        $make_sidebar('../../', true),
        $bc
    );
    // lien canonique correct par article : on remplace dans le layout générique
    $page = str_replace('<link rel="canonical" href="' . $CONFIG['site_url'] . '/">',
                         '<link rel="canonical" href="' . $a['url'] . '">', $page);

    $dir = "$out/article/" . $a['slug'];
    @mkdir($dir, 0775, true);
    file_put_contents("$dir/index.html", $page);
}

/* ---- Pages catégories et tags ---- */
// Rendu d'une page de liste (catégorie ou tag)
$render_list_page = function (string $label, array $list, string $title, string $filterType) use ($CONFIG, $out, $make_sidebar) {
    $cards = '';
    foreach ($list as $a) {
        $cover = $a['image']
            ? '<img class="cover" src="../../assets/' . htmlspecialchars($a['image']) . '" alt="' . htmlspecialchars($a['image_alt']) . '" loading="lazy">'
            : '';
        $cards .= '<a class="card" href="../../article/' . $a['slug'] . '/">'
            . '<span class="card-body">'
            . '<div class="meta">' . $a['date_fr'] . '</div>'
            . '<h2>' . htmlspecialchars($a['title']) . '</h2>'
            . '<p>' . htmlspecialchars($a['description']) . '</p>'
            . '</span>'
            . $cover
            . '</a>';
    }
    $page = layout($title . ' — ' . $CONFIG['site_name'],
                   'Articles ' . $filterType . ' : ' . $title,
                   '<p><a class="filter-chip" href="../../">&#10006; Retirer le filtre</a></p>'
                   . '<h1>' . htmlspecialchars($title) . '</h1>'
                   . '<p class="meta">' . count($list) . ' article(s)</p>'
                   . '<section class="cards">' . $cards . '</section>',
                   $CONFIG, null, '../../',
                   $make_sidebar('../../'),
                   [['label' => 'Accueil', 'rel' => '../../', 'abs' => $CONFIG['site_url'] . '/'],
                    ['label' => $title, 'rel' => '', 'abs' => '']]);
    return $page;
};

foreach ($categories as $slug => $data) {
    $dir = "$out/categorie/$slug";
    @mkdir($dir, 0775, true);
    file_put_contents("$dir/index.html", $render_list_page($data['label'], $data['articles'], $data['label'], 'par catégorie'));
}
foreach ($allTags as $slug => $data) {
    $dir = "$out/tag/$slug";
    @mkdir($dir, 0775, true);
    file_put_contents("$dir/index.html", $render_list_page($data['label'], $data['articles'], 'Tag : ' . $data['label'], 'par tag'));
}

/* ---- Page 404 ---- */
file_put_contents("$out/404.html", layout(
    'Page introuvable — ' . $CONFIG['site_name'],
    'Cette page n\'existe pas ou a été déplacée.',
    '<h1>404 — Page introuvable</h1>'
    . '<p>La page que vous cherchez n\'existe pas (ou n\'existe plus).</p>'
    . '<p><a href="">&larr; Retour à l\'accueil</a></p>',
    $CONFIG, null, '', $make_sidebar('')
));

/* ---- Mentions légales (LCEN) ---- */
$owner = $CONFIG['author'];
$host = $CONFIG['host_name'];
$mail = $CONFIG['email'];
$ml_body = <<<HTML
<article>
<h1>Mentions légales</h1>

<h2>Éditeur du site</h2>
<p>Le site est édité par <strong>$owner</strong>, à titre personnel et non professionnel.<br>
Contact : <a href="mailto:$mail">$mail</a></p>
<p>Conformément à l'article 6 de la LCEN, l'éditeur, personne physique non professionnelle,
choisit de ne pas publier son adresse personnelle.</p>

<h2>Hébergement</h2>
<p>Le site est <strong>auto-hébergé</strong> par l'éditeur ($host) sur un serveur privé situé en France.<br>
Directeur de la publication : $owner.</p>

<h2>Propriété intellectuelle</h2>
<p>Sauf mention contraire, les contenus de ce site (textes, illustrations, images) sont la
propriété de l'éditeur. Toute reproduction sans autorisation est interdite. Les outils
libres et morceaux de code présentés restent soumis à leurs licences d'origine.</p>

<h2>Données personnelles</h2>
<p>Ce site ne dépose <strong>aucun cookie</strong>, n'embarque aucun traceur (publicité,
statistiques, réseaux sociaux) et ne collecte aucune donnée via formulaire. Aucune donnée
personnelle n'est donc traitée ni transmise à des tiers.</p>

<h2>Liens externes</h2>
<p>Ce site contient des liens vers des sites externes dont l'éditeur n'est pas responsable.</p>
</article>
HTML;
if (!is_dir("$out/mentions-legales")) @mkdir("$out/mentions-legales", 0775, true);
file_put_contents("$out/mentions-legales/index.html", layout(
    'Mentions légales — ' . $CONFIG['site_name'],
    'Mentions légales, hébergement et données personnelles du site de Basile Malin.',
    $ml_body,
    $CONFIG, null, '', $make_sidebar(''),
    [['label' => 'Accueil', 'rel' => '', 'abs' => $CONFIG['site_url'] . '/'],
     ['label' => 'Mentions légales', 'rel' => '', 'abs' => '']]
));

/* ---- sitemap.xml ---- */
$sitemap = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
$add = function (string $loc, string $date) use (&$sitemap) {
    $sitemap .= "  <url><loc>$loc</loc><lastmod>$date</lastmod></url>\n";
};
$add($CONFIG['site_url'] . '/', date('Y-m-d'));
foreach ($articles as $a) $add($a['url'], $a['date']);
for ($i = 2; $i <= $totalPages; $i++) $add($CONFIG['site_url'] . '/page/' . $i . '/', date('Y-m-d'));
foreach (array_keys($categories) as $slug) $add($CONFIG['site_url'] . '/categorie/' . $slug . '/', date('Y-m-d'));
foreach (array_keys($allTags) as $slug) $add($CONFIG['site_url'] . '/tag/' . $slug . '/', date('Y-m-d'));
$sitemap .= "</urlset>\n";
file_put_contents("$out/sitemap.xml", $sitemap);

/* ---- feed.xml (RSS) ---- */
$rss = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<rss version="2.0"><channel>' . "\n"
    . '  <title>' . htmlspecialchars($CONFIG['site_name']) . '</title>' . "\n"
    . '  <link>' . $CONFIG['site_url'] . '/</link>' . "\n"
    . '  <description>' . htmlspecialchars($CONFIG['description']) . '</description>' . "\n";
foreach (array_slice($articles, 0, 10) as $a) {
    $rss .= "  <item>\n"
        . '    <title>' . htmlspecialchars($a['title']) . "</title>\n"
        . '    <link>' . $a['url'] . "</link>\n"
        . '    <guid>' . $a['url'] . "</guid>\n"
        . '    <pubDate>' . (new DateTime($a['date']))->format(DATE_RFC822) . "</pubDate>\n"
        . '    <description>' . htmlspecialchars($a['description']) . "</description>\n"
        . "  </item>\n";
}
$rss .= '</channel></rss>';
file_put_contents("$out/feed.xml", $rss);

/* ---- robots.txt ---- */
file_put_contents("$out/robots.txt",
    "User-agent: *\nDisallow: \nSitemap: " . $CONFIG['site_url'] . "/sitemap.xml\n");

echo "✔ " . count($articles) . " article(s) généré(s) dans $out\n";
echo "  Déploiement : rsync -rav --delete $out/ user@serveur:/var/www/basile/\n";