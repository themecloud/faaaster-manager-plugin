<?php

/**
 * A CSS background identified as LCP and declared in a stylesheet FILE of the page (Elementor
 * post-<id>.css under uploads): its rule is copied into the page with the variant, the file is
 * never written. Shapes taken from www.faaaster.io and www.stephaneolivier.fr (15/11/2026).
 */
wp_mkdir_p(WP_CONTENT_DIR . '/uploads/elementor/css');
$sheet_file = WP_CONTENT_DIR . '/uploads/elementor/css/post-7.css';
$sheet_href = home_url('/wp-content/uploads/elementor/css/post-7.css?ver=1794461218');
$overlay = '.elementor-7 .elementor-element.elementor-element-293ff22 > .elementor-background-overlay';
$large_path = '/wp-content/uploads/2026/11/large.png';
$large_variant = home_url($resolver->url($source));
// Four elements share the class on www.faaaster.io: tag, id and class cannot single one out.
$overlay_target = array('tag' => 'DIV', 'id' => null, 'class' => 'elementor-background-overlay');
function external_sheet($css) {
    file_put_contents($GLOBALS['sheet_file'], $css);
    return $css;
}
function external_document($links, $body_links = '') {
    return '<html><head><style>.elementor-background-overlay{width:400px;height:200px}</style>' . $links . '</head><body class="elementor-7">'
        . '<div class="elementor-element elementor-element-293ff22"><div class="elementor-background-overlay"></div></div>'
        . '<div class="elementor-element elementor-element-aaa"><div class="elementor-background-overlay"></div></div>' . $body_links . '</body></html>';
}
function external_samples($url, $target, array $viewports = array(400, 1200)) {
    $samples = array();
    foreach ($viewports as $viewport) { $samples[$viewport] = array('url' => $url, 'target' => $target, 'width' => 400, 'height' => 200); }
    return $samples;
}
function external_style($page) {
    return preg_match('~<style data-faaaster-images="css-lcp">(.*?)</style>~s', $page, $match) ? $match[1] : null;
}
$sheet_link = '<link rel="stylesheet" id="elementor-post-7-css" href="' . $sheet_href . '" media="all">';
$sheet_css = external_sheet('.elementor-7 .elementor-element.elementor-element-aaa{background-color:#fff;}' . $overlay
    . '{background-image:url("' . $large . '");background-position:center center;opacity:1;}@media(max-width:767px){' . $overlay . '{background-position:top left;}}');
$external_document = external_document($sheet_link);
$original_samples = external_samples($large, $overlay_target);
$original_collection = css_lcp_collection($original_samples);

check('original upload address has a path identity', FaaasterImageSources::upload_path($large), $large_path);
check('variant address has the same path identity', FaaasterImageSources::upload_path(home_url($resolver->url($source, 960))), $large_path);
check('root-relative address has the same path identity', FaaasterImageSources::upload_path($large_path), $large_path);
check('path identity uses the resolver encoding', FaaasterImageSources::upload_path(home_url('/wp-content/uploads/2026/11/a b(1)+é.png')), '/wp-content/uploads/2026/11/a%20b%281%29%2B%C3%A9.png');
check('browser-encoded and resolver-encoded paths agree', FaaasterImageSources::upload_path(home_url('/wp-content/uploads/2026/11/a%20b(1)+%C3%A9.png')), '/wp-content/uploads/2026/11/a%20b%281%29%2B%C3%A9.png');
foreach (array('https://foreign.test' . $large_path, $large . '?v=1', $large . '#x', home_url('/wp-content/themes/a.png'),
    home_url('/wp-content/uploads/../secret.png'), home_url('/wp-content/uploads/a%2fb.png'), home_url('/wp-content/uploads/a%zz.png'), array()) as $unsafe) {
    check('no path identity for ' . (is_string($unsafe) ? $unsafe : 'a non-string'), FaaasterImageSources::upload_path($unsafe), null);
}
check('stylesheet of the page resolved inside uploads', $resolver->stylesheet($sheet_href), realpath($sheet_file));
file_put_contents(WP_CONTENT_DIR . '/outside.css', 'x{}');
symlink(WP_CONTENT_DIR . '/outside.css', WP_CONTENT_DIR . '/uploads/elementor/css/escape.css');
foreach (array(home_url('/wp-content/themes/theme/style.css'), 'https://cdn.test/wp-content/uploads/elementor/css/post-7.css',
    home_url('/wp-content/uploads/elementor/css/missing.css'), home_url('/wp-content/uploads/elementor/css/escape.css'),
    home_url('/wp-content/uploads/elementor/css/../../../outside.css'), $large, home_url('/wp-content/uploads/elementor/css/post-7.css#x')) as $unsafe) {
    check('stylesheet refused: ' . $unsafe, $resolver->stylesheet($unsafe), null);
}

$groups = FaaasterImageBackgrounds::css_lcp_groups($original_collection);
check('original address forms a group without variant key', array($groups[0]['key'], $groups[0]['path'], $groups[0]['target']), array(null, $large_path, $overlay_target));
list($external_page, $external_links, $external_header) = transform_html($external_document, $original_collection, 971);
$external_css = external_style($external_page);
check('stylesheet rule copied into the page', is_string($external_css));
check('copied rule keeps the exact selector', strpos($external_css, '{' . $overlay . '{background-image:url("' . FaaasterImageSources::PREFIX) !== false);
check('copied rule changes the image only', preg_match_all('~\{([a-z-]+):~', str_replace($overlay, '', $external_css), $properties) > 0 && array_unique($properties[1]) === array('background-image'));
check('copied rule carries adaptive variants only', strpos($external_css, '/w0/') === false && strpos($external_css, '/w480/') !== false && strpos($external_css, '/w1280/') !== false);
check('copied rule is written after its stylesheet, in head', strpos($external_page, 'post-7.css') < strpos($external_page, 'data-faaaster-images="css-lcp"')
    && strpos($external_page, 'data-faaaster-images="css-lcp"') < strpos($external_page, '</head>'));
check('copied rule needs no element marker', strpos($external_page, 'data-faaaster-css-lcp'), false);
check('page markup otherwise untouched', str_replace('<style data-faaaster-images="css-lcp">' . $external_css . '</style>', '', $external_page), $external_document);
check('stylesheet file is never written', file_get_contents($sheet_file), $sheet_css);
check('copied rule preloads its variants', strpos($external_links, '/w480/') !== false && strpos($external_links, '/w1280/') !== false);
check('copied rule never preloads the original or w0', strpos($external_links, '/w0/') === false && strpos($external_links, 'href="' . $large . '"') === false);
check('copied rule dimensions stored under the path identity', $GLOBALS['meta'][971][FaaasterImageBackgrounds::SOURCES_META], array('CSS:' . $large_path => array(array('width' => 1600, 'height' => 800))));
list($external_twice) = transform_html($external_page, $original_collection, 0);
check('copied rule is not copied twice', substr_count($external_twice, 'data-faaaster-images="css-lcp"'), 1);

// Once adapted, visitors measure the variant; classes may change while a slideshow runs.
$variant_collection = css_lcp_collection(external_samples($large_variant, $overlay_target));
list($variant_page, $variant_links) = transform_html($external_document, $variant_collection, 972);
check('variant samples keep the same copied rule', array($variant_page, $variant_links), array($external_page, $external_links));
$mixed = array(300 => array('url' => $large, 'target' => $overlay_target, 'width' => 400, 'height' => 200),
    400 => array('url' => home_url($resolver->url($source, 960)), 'target' => array('tag' => 'DIV', 'id' => null, 'class' => 'elementor-background-overlay active'), 'width' => 400, 'height' => 200),
    1100 => array('url' => $large_variant, 'target' => $overlay_target, 'width' => 400, 'height' => 200),
    1200 => array('url' => $large, 'target' => $overlay_target, 'width' => 400, 'height' => 200));
$mixed_collection = css_lcp_collection($mixed, array(480), 2);
$mixed_groups = FaaasterImageBackgrounds::css_lcp_groups($mixed_collection);
check('original and variant samples agree on the file', array($mixed_groups[0]['path'], $mixed_groups[0]['key'], $mixed_groups[0]['target'], $mixed_groups[1]['target']), array($large_path, null, null, $overlay_target));
list($mixed_page) = transform_html($external_document, $mixed_collection, 0);
check('mixed samples keep the same copied rule', $mixed_page, $external_page);
check('samples turning from original to variant request no purge', FaaasterImagePurges::fingerprint($original_collection, 971), FaaasterImagePurges::fingerprint($variant_collection, 971));
check('first measurement of a stylesheet background requests a page', FaaasterImagePurges::fingerprint($original_collection, 0) !== FaaasterImagePurges::fingerprint($original_collection, 971));
$wider = $original_samples; $wider[400]['width'] = 650;
check('copied rule bucket change requests a purge', FaaasterImagePurges::fingerprint($original_collection, 971) !== FaaasterImagePurges::fingerprint(css_lcp_collection($wider), 971));
$noisy = $original_samples; $noisy[400]['width'] = 400.2;
check('copied rule geometric noise requests no purge', FaaasterImagePurges::fingerprint($original_collection, 971), FaaasterImagePurges::fingerprint(css_lcp_collection($noisy), 971));
$other_file = css_lcp_collection(array(400 => array('url' => $large, 'target' => $overlay_target, 'width' => 400, 'height' => 200),
    300 => array('url' => $small, 'target' => $overlay_target, 'width' => 400, 'height' => 200)), array(), 2);
check('samples naming two files form no group', FaaasterImageBackgrounds::css_lcp_groups($other_file), array(null));

// A slide of an Elementor slideshow: its own rule, selector list, !important and an at-rule around.
$slide = '.elementor-7 .elementor-element.elementor-element-6bb7e809 .elementor-repeater-item-3f9a197 .swiper-slide-bg';
external_sheet('@media (min-width:768px){' . $slide . ",\n" . $slide . ':not(.x){background:url(' . $large_path . ') center / cover no-repeat !important}}'
    . '.elementor-7 .elementor-repeater-item-6e09a49 .swiper-slide-bg{background-image:url("' . $small . '")}');
list($slide_page) = transform_html($external_document, $original_collection, 0);
$slide_css = external_style($slide_page);
check('selector list, at-rule and !important copied as written', is_string($slide_css)
    && strpos($slide_css, '{@media (min-width:768px){' . $slide . ",\n" . $slide . ':not(.x){background-image:url("' . FaaasterImageSources::PREFIX) !== false
    && substr_count($slide_css, '!important}}}') === substr_count($slide_css, 'background-image:'));
check('other slides are left to their own rule', strpos($slide_css, 'small.png'), false);

$unmatched_before = faaaster_image_optimization_state()['css_lcp_unmatched'];
$refused = array(
    'file named by two rules' => $overlay . '{background-image:url("' . $large . '")}.footer{background-image:url("' . $large . '")}',
    'responsive variant of the same selector' => $overlay . '{background-image:url("' . $large . '")}@media(max-width:1024px){' . $overlay . '{background-image:url("' . $small . '")}}',
    'same selector reset elsewhere' => $overlay . '{background-image:url("' . $large . '")}@media(max-width:767px){.x,' . str_replace(' > ', '>', $overlay) . '{background:none}}',
    'second layer' => $overlay . '{background-image:linear-gradient(#000,#fff),url("' . $large . '")}',
    'shorthand with a function' => $overlay . '{background:url("' . $large . '") rgba(0,0,0,.5)}',
    'two images' => $overlay . '{background-image:url("' . $large . '"),url("' . $small . '")}',
    'markup in the selector' => $overlay . '[data-x="</style><script>alert(1)</script>"]{background-image:url("' . $large . '")}',
    'escape in the selector' => '.md\\:hero{background-image:url("' . $large . '")}',
    'comment in the selector' => $overlay . '/* x */{background-image:url("' . $large . '")}',
    'nested rule' => '.card{' . $overlay . '{background-image:url("' . $large . '")}}',
    'nesting selector' => '.card{&.hero{background-image:url("' . $large . '")}}',
    'cascade layer' => '@layer theme{' . $overlay . '{background-image:url("' . $large . '")}}',
    'relative address' => $overlay . '{background-image:url("../../2026/11/large.png")}',
    'another file' => $overlay . '{background-image:url("' . $small . '")}',
    'no rule' => $overlay . '{color:red}',
);
foreach ($refused as $label => $css) {
    external_sheet($css);
    list($refused_page, $refused_links) = transform_html($external_document, $original_collection, 0);
    check('author rule left alone: ' . $label, $refused_page, $external_document);
    check('no variant preload: ' . $label, strpos($refused_links, FaaasterImageSources::PREFIX), false);
}
$unmatched_after = faaaster_image_optimization_state()['css_lcp_unmatched'];
check('each refused stylesheet rule counts one reason per group', array_sum($unmatched_after) - array_sum($unmatched_before), 2 * count($refused));
check('refused stylesheet rules count under the existing reasons', array_keys($unmatched_after), FaaasterImageOptimization::CSS_LCP_REASONS);

// The page itself may hold the competing rule, or forbid a new STYLE element.
external_sheet($sheet_css);
$inline_competitor = str_replace('</style>', $overlay . '{background-image:none}</style>', $external_document);
list($competitor_page) = transform_html($inline_competitor, $original_collection, 0);
check('competing rule in a STYLE block keeps the author cascade', external_style($competitor_page), null);
foreach (array("style-src 'self' 'nonce-abc'" => false, "style-src 'self'" => false, "default-src 'self'" => false, "style-src 'unsafe-inline' 'nonce-abc'" => false,
    "default-src 'self' 'unsafe-inline'; style-src-elem 'self'" => false, "style-src 'self' 'unsafe-inline'" => true, "default-src 'self'; style-src 'self' 'unsafe-inline'" => true,
    "frame-ancestors 'self'; upgrade-insecure-requests" => true) as $policy => $allowed) {
    $policy_document = str_replace('<head>', '<head><meta http-equiv="Content-Security-Policy" content="' . esc_attr($policy) . '">', $external_document);
    list($policy_page, $policy_links) = transform_html($policy_document, $original_collection, 0);
    check('style policy ' . $policy . ($allowed ? ' allows the copied rule' : ' leaves the stylesheet rule alone'),
        array(external_style($policy_page) !== null, strpos($policy_links, FaaasterImageSources::PREFIX) !== false), array($allowed, $allowed));
}
check('policy without style directive does not restrict inline styles', FaaasterImageVisitor::restricts_inline_styles(array("frame-ancestors 'none'", '')), false);
foreach (array('print stylesheet' => str_replace('media="all"', 'media="print"', $sheet_link), 'alternate stylesheet' => str_replace('rel="stylesheet"', 'rel="alternate stylesheet"', $sheet_link),
    'disabled stylesheet' => str_replace('<link ', '<link disabled ', $sheet_link), 'preloaded stylesheet' => str_replace('rel="stylesheet"', 'rel="preload" as="style"', $sheet_link),
    'stylesheet inside noscript' => '<noscript>' . $sheet_link . '</noscript>', 'theme stylesheet' => str_replace($sheet_href, home_url('/wp-content/themes/theme/style.css'), $sheet_link)) as $label => $link) {
    list($ignored_page) = transform_html(external_document($link), $original_collection, 0);
    check('not read: ' . $label, external_style($ignored_page), null);
}
file_put_contents(WP_CONTENT_DIR . '/uploads/elementor/css/huge.css', str_repeat('.a{color:red}', 45000));
$huge_link = '<link rel="stylesheet" href="' . home_url('/wp-content/uploads/elementor/css/huge.css') . '">';
list($huge_page) = transform_html(external_document($sheet_link . $huge_link), $original_collection, 0);
check('an unread oversized stylesheet forbids the copy', external_style($huge_page), null);
list($many_page) = transform_html(external_document(str_repeat($sheet_link, 33)), $original_collection, 0);
check('too many stylesheets forbid the copy', external_style($many_page), null);
list($twice_linked_page) = transform_html(external_document($sheet_link . $sheet_link), $original_collection, 0);
check('a stylesheet linked twice is read once', is_string(external_style($twice_linked_page)));

// Elementor prints late stylesheets in the body: the copy must still come after its source.
list($body_page) = transform_html(external_document('', $sheet_link), $original_collection, 0);
check('rule of a body stylesheet is written at the end of the body', strpos($body_page, 'post-7.css') < strpos($body_page, 'data-faaaster-images="css-lcp"')
    && strpos($body_page, '</head>') < strpos($body_page, 'data-faaaster-images="css-lcp"') && is_string(external_style($body_page)));

// A source of a STYLE block keeps the in-place path, and a group is adapted alone.
list($style_page) = transform_html($css_document, $css_collection, 0);
check('STYLE block sources keep the in-place variable', strpos($style_page, 'var(--faaaster-css-lcp-') !== false && external_style($style_page) === null);
$one_group = css_lcp_collection(array(1200 => $original_samples[1200]));
list($one_page, $one_links) = transform_html($external_document, $one_group, 0);
check('only the measured viewport group is adapted', strpos(external_style($one_page), '@media') === 0 && substr_count($one_links, 'rel="preload"') > 0);
check('cold page reads nothing and changes nothing', transform_html($external_document, new OD_URL_Metric_Group_Collection(array(), str_repeat('a', 32), array(480), 1, 604800), 0)[0], $external_document);
$_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] = 'css_lcp';
FaaasterImageOptimization::initialize();
check('disabled CSS LCP copies no rule', transform_html($external_document, $original_collection, 0)[0], $external_document);
unset($_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES']);
FaaasterImageOptimization::initialize();

// Chrome: the copied rule must win over the stylesheet, and only the variant may be downloaded.
external_sheet('.elementor-7 .elementor-element.elementor-element-aaa{background-color:#fff;}' . $overlay . '{background-image:url("' . $large_path . '");background-position:center center;}');
$browser_document = str_replace('<div class="elementor-background-overlay"></div></div><div class="elementor-element elementor-element-aaa">',
    '<div id="hero" class="elementor-background-overlay"></div></div><div class="elementor-element elementor-element-aaa">', $external_document);
list($browser_page, $browser_links, $browser_header) = transform_html($browser_document, $original_collection, 0);
check('browser fixture is adapted', is_string(external_style($browser_page)));
$browser_fixtures['css-lcp-external'] = array('html' => str_replace('</head>', $browser_links . '</head>', $browser_page), 'link' => $browser_header, 'css_lcp' => true, 'external' => true);
// The real detector, on a cold page, must measure the original the stylesheet names.
$browser_fixtures['css-lcp-collect-external'] = array('html' => od_optimize_template_output_buffer($browser_document), 'link' => null, 'css_collect' => true, 'original' => true);
