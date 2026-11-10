<?php

check('effective parser declares its repair revision', FaaasterImageOptimization::parser_revision(), 1);
check('parser revision participates in metrics ETag', apply_filters('od_current_url_metrics_etag_data', array('original' => 42)), array('original' => 42, 'faaaster_parser_revision' => 1, 'faaaster_css_lcp' => 1, 'faaaster_features' => FaaasterImageOptimization::FEATURES));

foreach (array(
    'valid' => '<h2><span>Title</span></h2>',
    'missing span close' => '<h2><span>Title</h2>',
    'missing two inline closes' => '<h2><span><em>Title</h2>',
    'extra span close' => '<h2>Title</span></h2>',
) as $name => $content) {
    $p = new OD_HTML_Tag_Processor('<html><head></head><body><main id="main">' . $content . '<img><img></main></body></html>');
    $script = '<script id="injection-probe"></script>';
    $p->append_body_html($script);
    $paths = array();
    while ($p->next_tag(array('tag_closers' => 'skip'))) {
        if ($p->get_tag() === 'IMG') {
            $paths[] = $p->get_xpath();
            $p->set_attribute('data-od-xpath', $p->get_xpath());
            $p->set_bookmark('probe');
            $p->seek('probe');
            $p->release_bookmark('probe');
        }
    }
    $rendered = $p->get_updated_html();
    check('correct injection position: ' . $name, strpos($rendered, $script) < strpos($rendered, '</body>'));
    check('actual injection verified: ' . $name, $p->was_body_html_injected($script));
    check('repaired sibling XPath: ' . $name, $paths, array("/HTML/BODY/MAIN[@id='main']/*[2][self::IMG]", "/HTML/BODY/MAIN[@id='main']/*[3][self::IMG]"));
}
foreach (array(
    '<html><head></head><body><div><section>Unclosed</div></body></html>',
    '<html><head></head><body><body>Duplicate</body></body></html>',
    '<html><head></head><body><span>No body closer</span></html>',
    '<html><head></head><body><span>No closing document tags',
) as $html) {
    $p = new OD_HTML_Tag_Processor($html);
    $script = '<script id="injection-probe"></script>';
    $p->append_body_html($script);
    $p->append_head_html('<style id="unsafe-head-injection"></style>');
    while ($p->next_tag(array('tag_closers' => 'skip'))) {}
    check('ambiguous/missing BODY prevents injection', strpos($p->get_updated_html(), $script), false);
    check('unrendered script is never acknowledged', $p->was_body_html_injected($script), false);
    check('ambiguous document prevents head injection too', strpos($p->get_updated_html(), 'unsafe-head-injection'), false);
}
require __DIR__ . '/optional.php';

$count = faaaster_image_optimization_state()['detection_injection_failures'];
FaaasterImageOptimization::detection_result(false, false);
FaaasterImageOptimization::detection_result(true, true);
check('unneeded/successful injection does not increment failures', faaaster_image_optimization_state()['detection_injection_failures'], $count);
FaaasterImageOptimization::detection_result(true, false);
check('failed necessary injection increments site total', faaaster_image_optimization_state()['detection_injection_failures'], $count + 1);

$marked_metrics = collection_for($adaptive)->get_flattened_url_metrics();
$old_metrics = array_map(function ($metric) {
    $data = $metric->jsonSerialize();
    unset($data[FaaasterImageOptimization::PARSER_METRIC_PROPERTY]);
    return new ImageTestMetric($data);
}, $marked_metrics);
$changed_etag = 'ffffffffffffffffffffffffffffffff';
$stale_collection = new OD_URL_Metric_Group_Collection($old_metrics, $changed_etag, array(480), 1, 604800);
check('OD normally exposes stale LCP', $stale_collection->get_first_group()->get_lcp_element() !== null);
$decisions = FaaasterImageOptimization::decision_metrics($stale_collection);
check('old parser metrics cannot decide LCP', $decisions->get_first_group()->get_lcp_element(), null);
check('old parser metrics cannot size backgrounds', $decisions->get_xpath_elements_map(), array());
check('filtering does not delete source metrics', count($stale_collection->get_flattened_url_metrics()), 2);
check('template filter excludes unmarked samples', apply_filters('od_template_url_metrics', $old_metrics, $changed_etag), array());
check('marked samples survive unrelated ETag changes', apply_filters('od_template_url_metrics', $marked_metrics, $changed_etag), $marked_metrics);
$marked_stale = new OD_URL_Metric_Group_Collection($marked_metrics, $changed_etag, array(480), 1, 604800);
check('native LCP remains available after unrelated ETag change', FaaasterImageOptimization::decision_metrics($marked_stale)->get_first_group()->get_lcp_element() !== null);
check('unrelated ETag change still requests native recollection', FaaasterImageOptimization::decision_metrics($marked_stale)->is_every_group_complete(), false);
check('revision schema hook is registered', has_filter('od_url_metric_schema_root_additional_properties', array('FaaasterImageOptimization', 'metric_schema')), 10);
check('REST stamping hook is registered', has_filter('rest_request_before_callbacks', array('FaaasterImageOptimization', 'stamp_metric_revision')), 10);

foreach (array('data-src' => 'data-srcset', 'data-lazy-src' => 'data-lazy-srcset') as $source_attribute => $set_attribute) {
    $document = '<html><head></head><body><img src="data:image/gif;base64,placeholder" ' . $source_attribute . '="' . $large . '"></body></html>';
    list($page) = transform_html($document);
    check('lazy original retained: ' . $source_attribute, first_tag_attribute($page, 'IMG', $source_attribute), $large);
    check('no direct eager srcset: ' . $source_attribute, first_tag_attribute($page, 'IMG', 'srcset'), null);
    check('lazy twin created: ' . $set_attribute, strpos(first_tag_attribute($page, 'IMG', $set_attribute), '/w0/') !== false);
    $document = str_replace('></body>', ' ' . $set_attribute . '="' . $small . ' 400w, ' . $large . ' 1600w"></body>', $document);
    list($page) = transform_html($document);
    check('lazy authored widths unchanged: ' . $set_attribute, strpos(first_tag_attribute($page, 'IMG', $set_attribute), 'small.png 400w') !== false && strpos(first_tag_attribute($page, 'IMG', $set_attribute), 'large.png 1600w') !== false);
    check('lazy candidate conversion never adds buckets', strpos(first_tag_attribute($page, 'IMG', $set_attribute), '/w320/'), false);
}
foreach (array('data-src' => 'data-srcset', 'data-lazy-src' => 'data-lazy-srcset') as $source_attribute => $set_attribute) {
    $authored_set = $small . ' 400w, ' . $large . ' 1600w';
    $document = '<html><head></head><body><img src="data:image/gif;base64,placeholder" '
        . $source_attribute . '="' . $large . '" srcset="' . $authored_set . '"></body></html>';
    list($page) = transform_html($document);
    check('authored direct srcset is retained: ' . $source_attribute, first_tag_attribute($page, 'IMG', 'srcset'), $authored_set);
    check('no density twin replaces a direct srcset: ' . $source_attribute, first_tag_attribute($page, 'IMG', $set_attribute), null);
    check('lazy data source remains original with direct srcset: ' . $source_attribute, first_tag_attribute($page, 'IMG', $source_attribute), $large);
    list($page) = transform_html(str_replace(' srcset=', ' ' . $set_attribute . '="' . esc_attr($authored_set) . '" srcset=', $document));
    check('an existing lazy set is still converted: ' . $set_attribute, strpos(first_tag_attribute($page, 'IMG', $set_attribute), '/w0/') !== false);
}
$mixed = $resolver->convert_candidates($large . ' 1600w, https://external.test/a.jpg 2000w');
check('lazy candidates independently preserve external sources', strpos($mixed, '/w0/') !== false && strpos($mixed, 'https://external.test/a.jpg 2000w') !== false);
check('implicit density candidate supported', strpos($resolver->convert_candidates($large), '/w0/') !== false);
check('data URI srcsets are not split on embedded commas', $resolver->convert_candidates('data:image/png;base64,abc 1x, ' . $large . ' 2x'), null);
$lazy_picture = '<html><head></head><body><picture><source type="image/png" data-srcset="' . $large . ' 1600w"><img data-src="' . $large . '" src="data:image/gif;base64,placeholder"></picture></body></html>';
list($page) = transform_html($lazy_picture);
check('lazy picture source converted', strpos(first_tag_attribute($page, 'SOURCE', 'data-srcset'), '/w0/') !== false);
check('lazy picture MIME follows conversion', first_tag_attribute($page, 'SOURCE', 'type'), 'image/webp');
check('lazy picture original retained', first_tag_attribute($page, 'IMG', 'data-src'), $large);
check('lazy picture not reprioritized', strpos($page, 'fetchpriority='), false);
list($page) = transform_html(str_replace('image/png', 'image/avif', $lazy_picture));
check('unsupported lazy picture remains whole', strpos($page, '/_faaaster/images/'), false);

$css = '.hero{background:url("' . $large . '")}/*url(' . $large . ')*/.text{content:"url(' . $large . ')"}';
$css_document = '<html><head><style>' . $css . '</style></head><body></body></html>';
list($page) = transform_html($css_document);
check('STYLE content converts local URL to w0', strpos($page, '/w0/') !== false);
check('STYLE strings and comments stay original', strpos($page, '/*url(' . $large . ')*/') !== false && strpos($page, 'content:"url(' . $large . ')"') !== false);
$cursor_css = '.hero{cursor:url("' . $unicode . '") 4 5,pointer;background:url("' . $large . '")}'
    . '@media(min-width:1px){.next{background-image:url("' . $small . '");cursor/**/:url("' . $unicode . '"),auto}}'
    . '.other{--cursor:url("' . $unicode . '");c\\75 rsor:url("' . $unicode . '"),auto;content:"background:url(' . $large . ')"}';
list($scoped_page) = transform_html('<html><head><style>' . $cursor_css . '</style></head><body></body></html>');
check('STYLE converts backgrounds across nested rules', substr_count($scoped_page, '/w0/'), 2);
check('STYLE cursor URLs remain original', strpos($scoped_page, 'cursor:url("' . $unicode . '") 4 5,pointer') !== false);
check('STYLE commented cursor property remains original', strpos($scoped_page, 'cursor/**/:url("' . $unicode . '"),auto') !== false);
check('STYLE escaped cursor property remains original', strpos($scoped_page, 'c\\75 rsor:url("' . $unicode . '"),auto') !== false);
check('STYLE custom-property URLs remain original', strpos($scoped_page, '--cursor:url("' . $unicode . '")') !== false);
check('STYLE strings are preserved in scoped conversion', strpos($scoped_page, 'content:"background:url(' . $large . ')"') !== false);
$hash_csp = "style-src 'sha256-abc='";
$csp_meta = '<meta http-equiv="Content-Security-Policy" content="' . esc_attr($hash_csp) . '">';
list($page) = transform_html(str_replace('</head>', $csp_meta . '</head>', $css_document));
check('late CSP meta protects all STYLE text', strpos($page, '/_faaaster/images/'), false);
list($page) = transform_html(str_replace('<style>', '<style nonce="abc">', str_replace('</head>', '<meta http-equiv="Content-Security-Policy" content="style-src &apos;nonce-abc&apos;"></head>', $css_document)));
check('nonce STYLE remains eligible', strpos($page, '/w0/') !== false && strpos($page, 'nonce="abc"') !== false);
check('style hash header policy detected', FaaasterImageVisitor::has_style_hash_policy(array($hash_csp)));
check('script-only hash does not disable STYLE', FaaasterImageVisitor::has_style_hash_policy(array("script-src 'sha256-abc='; style-src 'self'")), false);
check('style-src overrides hashed default-src', FaaasterImageVisitor::has_style_hash_policy(array("default-src 'sha256-abc='; style-src 'self'")), false);

// Exercise native OD output, not just a hand-written detector insertion.
require __DIR__ . '/runtime-stubs.php';
$wp->query_vars = array();
$wp_the_query = new WP_Query();
foreach ($optional_native_documents as $label => $document) {
    $native_optional = od_optimize_template_output_buffer($document);
    check('native detector on optional closures: ' . $label, strpos($native_optional, 'id="optimization-detective-detect-args"') !== false);
    $browser_fixtures['parser-optional-' . preg_replace('/[^a-z0-9]+/', '-', $label)] = array('html' => $native_optional, 'link' => null, 'parser' => true);
}

$count = faaaster_image_optimization_state()['detection_injection_failures'];
$native_document = '<html><head></head><body><div id="page"><h2><span><em>Title</h2><img src="' . $large . '" width="400"></div></body></html>';
$native_output = od_optimize_template_output_buffer($native_document);
check('native detector arguments actually injected', strpos($native_output, 'id="optimization-detective-detect-args"') !== false);
check('native detector injected before actual body closer', strpos($native_output, 'id="optimization-detective-detect-args"') < strpos($native_output, '</body>'));
check('native detector gets corrected XPath', first_tag_attribute($native_output, 'IMG', 'data-od-xpath'), "/HTML/BODY/DIV[@id='page']/*[2][self::IMG]");
check('native endpoint carries parser revision', strpos($native_output, 'faaaster_parser_revision=1') !== false);
check('successful native injection does not increment total', faaaster_image_optimization_state()['detection_injection_failures'], $count);
$ambiguous_output = od_optimize_template_output_buffer(str_replace('<h2><span><em>Title</h2>', '<section><div>Unclosed</section>', $native_document));
check('ambiguous native HTML has no detector injection', strpos($ambiguous_output, 'id="optimization-detective-detect-args"'), false);
check('ambiguous native injection increments total once', faaaster_image_optimization_state()['detection_injection_failures'], $count + 1);

$old_request = clone $purge_context;
$old_request->url_metric = $old_metrics[0];
$old_request->request = new class { public function get_param($name) { return $name === 'cache_purge_post_id' ? 4243 : null; } };
$pending_before = get_post_meta(4243, FaaasterImagePurges::PENDING, true);
FaaasterImagePurges::stored($old_request);
check('old cached page metrics cannot create XPath purge decisions', get_post_meta(4243, FaaasterImagePurges::PENDING, true), $pending_before);
check('other REST endpoints are unchanged', FaaasterImageOptimization::detect_rest_url('https://client.test/wp-json/wp/v2/media', 'wp/v2/media'), 'https://client.test/wp-json/wp/v2/media');

$library_manifest = json_decode(file_get_contents(__DIR__ . '/fixtures/lazyload/manifest.json'), true);
foreach ($library_manifest as $name => $library) {
    check('real loader fixture hash: ' . $name, hash_file('sha256', __DIR__ . '/fixtures/lazyload/' . $name . '.js'), $library['sha256']);
    check('loader license retained: ' . $name, hash_file('sha256', __DIR__ . '/fixtures/lazyload/' . $library['license']), $library['license_sha256']);
}
$vendor_manifest = json_decode(file_get_contents(__DIR__ . '/../../vendor/image-optimization/manifest.json'), true);
$parser_patch = $vendor_manifest['optimization-detective']['patches'][0];
check('declared backport diff integrity', hash_file('sha256', __DIR__ . '/../../vendor/image-optimization/' . $parser_patch['file']), $parser_patch['sha256']);
foreach ($vendor_manifest['optimization-detective']['patches'][0]['upstream_files'] as $name => $hash) {
    check('original client OD fixture: ' . $name, hash_file('sha256', __DIR__ . '/fixtures/od-upstream/' . $name), $hash);
}

$placeholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
foreach (array('lazysizes', 'vanilla', 'rocket', 'litespeed', 'smush', 'swiper8') as $loader) {
    $src_attr = $loader === 'rocket' ? 'data-lazy-src' : 'data-src';
    $set_attr = $loader === 'rocket' ? 'data-lazy-srcset' : 'data-srcset';
    $class = in_array($loader, array('lazysizes', 'smush'), true) ? 'lazyload' : ($loader === 'swiper8' ? 'swiper-lazy' : 'lazy');
    foreach (array('twin', 'set', 'picture') as $kind) {
        $img = '<img id="below" class="' . $class . '" width="400" height="200" src="' . $placeholder . '" ' . $src_attr . '="' . $large . '"'
            . ($loader === 'litespeed' ? ' data-lazyloaded="1"' : '')
            . ($kind === 'set' ? ' sizes="400px" data-sizes="400px" ' . $set_attr . '="' . $small . ' 400w, ' . $large . ' 1600w"' : '') . '>';
        if ($kind === 'picture') {
            $img = '<picture><source type="image/png" sizes="400px" ' . $set_attr . '="' . $large . ' 1600w">' . $img . '</picture>';
        }
        $document = '<html><head></head><body><div style="margin-top:3000px;width:400px;height:200px"'
            . ($loader === 'swiper8' ? ' class="swiper"' : '') . '>';
        $document .= $loader === 'swiper8' ? '<div class="swiper-wrapper"><div class="swiper-slide">' . $img . '</div></div>' : $img;
        $document .= '</div></body></html>';
        list($page, $links, $header) = transform_html($document);
        $browser_fixtures['lazy-' . $loader . '-' . $kind] = array('html' => str_replace('</head>', $links . '</head>', $page), 'link' => $header, 'loader' => $loader);
    }
}
$document = '<html><head></head><body><div class="swiper" style="margin-top:3000px;width:400px;height:200px"><div class="swiper-wrapper"><div class="swiper-slide"><img id="below" loading="lazy" width="400" height="200" src="' . $large . '"></div></div></div></body></html>';
list($page, $links, $header) = transform_html($document);
$browser_fixtures['lazy-swiper11-native'] = array('html' => str_replace('</head>', $links . '</head>', $page), 'link' => $header, 'loader' => 'swiper11');

$root_source = str_replace('https://client.test', '', $large);
$style_css = '#hero{width:400px;height:200px;background-image:url("' . $root_source . '")}';
$document = '<html><head><style>' . $style_css . '</style></head><body><div id="hero"></div></body></html>';
list($page, $links, $header) = transform_html($document);
$browser_fixtures['style-block'] = array('html' => $page, 'link' => $header);
$digest = base64_encode(hash('sha256', $style_css, true));
$policy = "style-src 'sha256-" . $digest . "'; script-src 'unsafe-inline'; img-src 'self' data:";
$csp_meta = '<meta http-equiv="Content-Security-Policy" content="' . esc_attr($policy) . '">';
list($page, $links, $header) = transform_html(str_replace('<style>', $csp_meta . '<style>', $document));
$browser_fixtures['style-hash'] = array('html' => $page, 'link' => $header, 'original' => true);
$csp_meta = '<meta http-equiv="Content-Security-Policy" content="style-src &apos;nonce-abc&apos;; script-src &apos;unsafe-inline&apos;; img-src &apos;self&apos; data:">';
list($page, $links, $header) = transform_html(str_replace('<style>', $csp_meta . '<style nonce="abc">', $document));
$browser_fixtures['style-nonce'] = array('html' => $page, 'link' => $header);
$browser_fixtures['parser-repair'] = array('html' => $native_output, 'link' => null);
