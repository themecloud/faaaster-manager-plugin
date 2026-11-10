<?php

function css_lcp_collection(array $samples, $breakpoints = array(480), $sample_size = 1) {
    $metrics = array();
    foreach ($samples as $viewport => $sample) {
        $target = $sample['target'] ?? array('tag' => 'DIV', 'id' => 'hero', 'class' => 'hero');
        $url = $sample['url'];
        $data = array('uuid' => 'css-' . $viewport, 'timestamp' => microtime(true), 'etag' => str_repeat('a', 32),
            'faaasterParserRevision' => 1, 'url' => home_url('/css-page/'), 'viewport' => array('width' => $viewport, 'height' => 900),
            'elements' => array(), 'lcpElementExternalBackgroundImage' => array('url' => $url) + $target,
            'faaasterCssLcp' => array('url' => $url, 'width' => $sample['width'], 'height' => $sample['height']));
        if (isset($sample['change'])) { $data = array_replace($data, $sample['change']); }
        $metrics[] = new ImageTestMetric($data);
    }
    return new OD_URL_Metric_Group_Collection($metrics, str_repeat('a', 32), $breakpoints, $sample_size, 604800);
}
$css_variant = home_url($resolver->url($source));
$css_document = '<html><head><style>.hero{width:400px;height:200px;background-image:url("' . $large . '")}</style></head><body><div id="hero" class="hero"></div></body></html>';
$css_samples = array(400 => array('url' => $css_variant, 'width' => 400, 'height' => 200), 1200 => array('url' => $css_variant, 'width' => 400, 'height' => 200));
$css_collection = css_lcp_collection($css_samples);
list($css_page, $css_links, $css_header) = transform_html($css_document, $css_collection, 991);
check('CSS LCP receives a target marker', first_tag_attribute($css_page, 'DIV', 'data-faaaster-css-lcp') !== null);
check('CSS LCP keeps its background in the authored stylesheet', first_tag_attribute($css_page, 'DIV', 'style'), null);
check('CSS LCP URL becomes a scoped variable', strpos($css_page, 'background-image:var(--faaaster-css-lcp-') !== false);
check('CSS LCP retains the existing STYLE instead of adding another', substr_count($css_page, '<style'), 1);
check('CSS LCP variant does not leak to descendants or pseudo-elements', strpos($css_page, '*,::before,::after{--faaaster-css-lcp-') !== false);
check('CSS LCP preloads only adaptive variants', strpos($css_links, '/w0/'), false);
check('CSS LCP preload includes DPR buckets', strpos($css_links, '/w480/') !== false && strpos($css_links, '/w1280/') !== false);
check('CSS LCP dimensions reuse the existing snapshot', isset($GLOBALS['meta'][991][FaaasterImageBackgrounds::SOURCES_META]['CSS:' . $resolver->url($source)]));
$browser_fixtures['css-lcp'] = array('html' => str_replace('</head>', $css_links . '</head>', $css_page), 'link' => $css_header, 'css_lcp' => true);
$data_html = str_replace('<html>', '<html style="background-image:url(data:image/gif;base64,placeholder)">', $css_document);
list($data_html_page, $data_html_links) = transform_html($data_html, $css_collection, 0);
check('a leading data background cannot initialize duplicate native CSS preloads', strpos($data_html_links, '/w0/'), false);
list($cold_page, $cold_links) = transform_html($css_document, new OD_URL_Metric_Group_Collection(array(), str_repeat('a', 32), array(480), 1, 604800), 992);
check('cold CSS keeps conversion only', strpos($cold_page, 'var(--faaaster-css-lcp-'), false);
check('cold CSS retains w0', strpos($cold_page, '/w0/') !== false);
check('cold CSS has no speculative adaptive preload', strpos($cold_links, '/w480/'), false);

$duplicate_document = str_replace('</body>', '<div id="hero" class="hero"></div></body>', $css_document);
list($duplicate_page) = transform_html($duplicate_document, $css_collection, 0);
check('ambiguous CSS identity is not adapted', strpos($duplicate_page, 'var(--faaaster-css-lcp-'), false);
$mismatch = $css_samples;
$mismatch[1200]['change'] = array('faaasterCssLcp' => array('url' => home_url('/different.png'), 'width' => 400, 'height' => 200));
list($partial_page, $partial_links) = transform_html($css_document, css_lcp_collection($mismatch), 0);
check('missing CSS geometry retains native preload in the other group', strpos($partial_links, '/w0/') !== false);
check('available CSS geometry still adapts its own group', strpos($partial_links, '/w480/') !== false);
check('CSS measurement variants normalize to one source', FaaasterImageSources::variant_key(home_url($resolver->url($source, 960))), $resolver->url($source));
check('foreign CSS variants are ignored', FaaasterImageSources::variant_key(str_replace('client.test', 'foreign.test', $css_variant)), null);
check('queried CSS variants are ignored', FaaasterImageSources::variant_key($css_variant . '?x=1'), null);
$three = array();
foreach (array(100 => 0, 200 => 480, 300 => 960) as $viewport => $width) { $three[$viewport] = array('url' => home_url($resolver->url($source, $width)), 'width' => 400, 'height' => 200); }
$normalized_groups = FaaasterImageBackgrounds::css_lcp_groups(css_lcp_collection($three, array(), 3));
check('DPR variants do not invalidate CSS source consensus', $normalized_groups[0]['key'], $resolver->url($source));
$noise = $css_samples; $noise[400]['width'] = 400.1; $noise[1200]['height'] = 200.1;
$larger = $css_samples; $larger[400]['width'] = 650;
check('CSS geometric noise does not request a purge', FaaasterImagePurges::fingerprint($css_collection, 991), FaaasterImagePurges::fingerprint(css_lcp_collection($noise), 991));
$GLOBALS['meta'][993][FaaasterImageBackgrounds::SOURCES_META] = array('CSS:' . $resolver->url($source) => array(array()));
check('invalid CSS dimensions do not break purge decisions', is_string(FaaasterImagePurges::fingerprint($css_collection, 993)));
check('CSS bucket changes request a purge', FaaasterImagePurges::fingerprint($css_collection, 991) !== FaaasterImagePurges::fingerprint(css_lcp_collection($larger), 991));
$other_dpr = $css_samples; $other_dpr[400]['url'] = home_url($resolver->url($source, 480));
check('CSS DPR URLs alone do not request a purge', FaaasterImagePurges::fingerprint($css_collection, 991), FaaasterImagePurges::fingerprint(css_lcp_collection($other_dpr), 991));

$nonce_document = str_replace('<style>', '<meta http-equiv="Content-Security-Policy" content="style-src &apos;nonce-abc&apos;; img-src &apos;self&apos; data:; script-src &apos;unsafe-inline&apos; &apos;self&apos;"><style nonce="abc">', $css_document);
list($nonce_page, $nonce_links, $nonce_header) = transform_html($nonce_document, $css_collection, 0);
check('CSS LCP preserves the author nonce', first_tag_attribute($nonce_page, 'STYLE', 'nonce'), 'abc');
check('CSS LCP nonce needs no additional STYLE', substr_count($nonce_page, '<style'), 1);
$browser_fixtures['css-lcp-nonce'] = array('html' => str_replace('</head>', $nonce_links . '</head>', $nonce_page), 'link' => $nonce_header, 'css_lcp' => true);
$hash_document = str_replace('<style>', '<meta http-equiv="Content-Security-Policy" content="style-src &apos;sha256-abc=&apos;"><style>', $css_document);
list($hash_page) = transform_html($hash_document, $css_collection, 0);
check('hashed STYLE is never adapted', strpos($hash_page, 'var(--faaaster-css-lcp-'), false);

$hover_document = str_replace('</style>', '.hero:hover{background-image:url("' . $small . '")}</style>', $css_document);
list($hover_page) = transform_html($hover_document, $css_collection, 0);
check('CSS LCP preserves hover source selection', strpos($hover_page, '.hero:hover{background-image:url("/_faaaster/images/') !== false);
$import_document = str_replace('<style>', '<style>@import url("/test-import.css");', $css_document);
list($import_page, $import_links, $import_header) = transform_html($import_document, $css_collection, 0);
check('CSS LCP keeps imports before generated rules', strpos($import_page, '@import') < strpos($import_page, '*,::before,::after'));
$browser_fixtures['css-lcp-import'] = array('html' => str_replace('</head>', $import_links . '</head>', $import_page), 'link' => $import_header, 'css_lcp' => true, 'import' => true);

$shared_document = str_replace('</style>', '.other{width:100px;height:100px;background-image:url("' . $large . '")}</style>', str_replace('</div>', '<div class="other" id="child"></div></div><div class="other" id="sibling"></div>', $css_document));
list($shared_page, $shared_links, $shared_header) = transform_html($shared_document, $css_collection, 0);
$browser_fixtures['css-lcp-shared'] = array('html' => str_replace('</head>', $shared_links . '</head>', $shared_page), 'link' => $shared_header, 'css_lcp' => true, 'shared' => true);
$mobile_document = str_replace('</style>', '@media(max-width:480px){.hero{width:300px;height:150px;background-image:url("' . $small . '")}}</style>', $css_document);
$mobile_samples = $css_samples; $mobile_samples[400] = array('url' => home_url($resolver->url($resolver->resolve($small))), 'width' => 300, 'height' => 150);
list($mobile_page, $mobile_links, $mobile_header) = transform_html($mobile_document, css_lcp_collection($mobile_samples), 0);
$browser_fixtures['css-lcp-media'] = array('html' => str_replace('</head>', $mobile_links . '</head>', $mobile_page), 'link' => $mobile_header, 'css_lcp' => true, 'mobile' => true);
$css_cover_document = str_replace(array($large, 'width:400px;height:200px'), array($cover_source, 'width:390px;height:844px;background-size:cover'), $css_document);
$cover_samples = array(400 => array('url' => home_url($resolver->url($resolver->resolve($cover_source))), 'width' => 390, 'height' => 844), 1200 => array('url' => home_url($resolver->url($resolver->resolve($cover_source))), 'width' => 390, 'height' => 844));
list($css_cover_page, $css_cover_links, $css_cover_header) = transform_html($css_cover_document, css_lcp_collection($cover_samples), 0);
$browser_fixtures['css-lcp-cover'] = array('html' => str_replace('</head>', $css_cover_links . '</head>', $css_cover_page), 'link' => $css_cover_header, 'css_lcp' => true, 'cover' => true);

// The actual detector must discover CSS geometry on a cold page using our module.
$native_css = od_optimize_template_output_buffer($css_document);
check('CSS geometry collector is registered in native OD arguments', strpos($native_css, '/assets/image-css-lcp.js?ver=1') !== false);
$browser_fixtures['css-lcp-collect'] = array('html' => $native_css, 'link' => null, 'css_collect' => true);
