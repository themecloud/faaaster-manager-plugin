<?php

function first_tag_attribute($html, $tag, $attribute)
{
    $p = new OD_HTML_Tag_Processor($html);
    $p->next_tag($tag);
    return $p->get_attribute($attribute);
}

$space_bg = image_png(1600, 800, 'escaped photo.png');
$paren_bg = image_png(1600, 800, 'paren).png');
$quote_bg = image_png(1600, 800, "quo'te.png");
$cases = array(
    'double quotes' => 'background-image:url("' . $large . '");color:red',
    'single quotes' => "background-image:url('" . $large . "');color:red",
    'unquoted' => 'background-image:URL(' . $large . ');color:red',
    'shorthand' => 'background:#fff url("' . $large . '") center/cover no-repeat;color:red',
    'space escape' => 'background-image:url(' . str_replace('%20', '\\ ', $space_bg) . ');color:red',
    'hex space escape' => 'background-image:url(' . str_replace('%20', '\\20 ', $space_bg) . ');color:red',
    'escaped parenthesis' => 'background-image:url(' . str_replace('%29', '\\)', $paren_bg) . ');color:red',
    'quoted escaped quote' => "background-image:url('" . str_replace('%27', "\\'", $quote_bg) . "');color:red",
    'quoted line continuation' => 'background-image:url("' . str_replace('large.png', "lar\\\nge.png", $large) . '");color:red',
);
$empty = new OD_URL_Metric_Group_Collection(array(), '0123456789abcdef0123456789abcdef', array(480), 1, 604800);
foreach ($cases as $name => $style) {
    $document = '<html><head></head><body><div id="hero" style="' . esc_attr($style) . '"></div></body></html>';
    list($transformed) = transform_html($document, $empty);
    $inline = first_tag_attribute($transformed, 'DIV', 'style');
    check('background parsed: ' . $name, strpos($inline, '/w0/') !== false);
    check('other declaration preserved: ' . $name, strpos($inline, 'color:red') !== false);
    check('no adaptive density before measurement: ' . $name, strpos($transformed, 'dppx'), false);
}

$style = 'background:url("' . $large . '") left top/cover, url(' . $small . ') center no-repeat, linear-gradient(red,blue);color:red';
$layers = '<html><head></head><body><div id="hero" style="' . esc_attr($style) . '"></div></body></html>';
list($layers_output) = transform_html($layers, $empty);
check('multiple local background URLs converted', substr_count(first_tag_attribute($layers_output, 'DIV', 'style'), '/w0/'), 2);
check('shorthand sizing preserved', strpos(first_tag_attribute($layers_output, 'DIV', 'style'), 'left top/cover') !== false);
check('gradient preserved', strpos(first_tag_attribute($layers_output, 'DIV', 'style'), 'linear-gradient(red,blue)') !== false);
$mixed_background = str_replace($small, 'https://external.test/photo.png', $layers);
list($mixed_output) = transform_html($mixed_background, $empty);
check('external background URL untouched', strpos(first_tag_attribute($mixed_output, 'DIV', 'style'), 'https://external.test/photo.png') !== false);
check('eligible layer converted independently', substr_count(first_tag_attribute($mixed_output, 'DIV', 'style'), '/w0/'), 1);

$untouched_styles = array(
    'content:"background-image:url(' . $large . ')";color:red',
    '/* background-image:url(' . $large . ') */ color:red',
    'background-image:url(https://external.test/a.png);color:red',
    'background-image:url(/wp-content/themes/theme/a.png);color:red',
    'background-image:url("data:image/svg+xml;fake,url(' . $large . ')");color:red',
);
foreach ($untouched_styles as $style) {
    $document = '<html><head></head><body><div style="' . esc_attr($style) . '"></div></body></html>';
    list($transformed) = transform_html($document, $empty);
    check('non-eligible CSS untouched', first_tag_attribute($transformed, 'DIV', 'style'), $style);
}

$adaptive = '<html><head></head><body><div id="hero" style="width:400px;height:200px;background-image:url(' . $large . ')"></div></body></html>';
list($adaptive_output, $adaptive_links) = transform_html($adaptive);
check('adaptive background uses density conditions', strpos($adaptive_output, 'resolution') !== false);
check('adaptive background includes custom admitted bucket', strpos($adaptive_output, '/w777/') !== false);
check('adaptive preload uses matching density conditions', strpos($adaptive_links, 'resolution') !== false);
check('adaptive preload uses admitted bucket', strpos($adaptive_links, '/w960/') !== false);
check('adaptive full-size conversion present', strpos($adaptive_output, '/w0/') !== false);
check('CSS contains no arbitrary width', strpos($adaptive_output, '/w800/'), false);
check('no upscale generated', strpos($adaptive_output, '/w1920/'), false);
check('exact boundary has exclusive successor', strpos($adaptive_output, '(resolution &gt; 1.6dppx)') !== false || strpos($adaptive_output, '(resolution > 1.6dppx)') !== false);
list($four_groups_output) = transform_html($adaptive, collection_for($adaptive, 'hero', 1, array(), array(320, 600, 1000, 1400), array(480, 782, 1080)));
check('identical viewport decisions share one CSS rule set', $four_groups_output, $adaptive_output);
check('no density rule devoted to sub-1 DPR buckets', strpos($adaptive_output, '/w150/') === false && strpos($adaptive_output, '/w320/') === false);
preg_match('~<style data-faaaster-images="[^"]+">(.*?)</style>~s', $four_groups_output, $compact_style);
check('single-layer background CSS stays below 3 KiB for four identical groups', strlen($compact_style[1]) < 3072);
list($jitter_a) = transform_html($adaptive, collection_for($adaptive, 'hero', 1, array(400 => 400.1, 1200 => 400.1)));
list($jitter_b) = transform_html($adaptive, collection_for($adaptive, 'hero', 1, array(400 => 400.9, 1200 => 400.9)));
check('measurement jitter within one admitted bucket leaves CSS unchanged', $jitter_a, $jitter_b);

$data_bg = '<html><head></head><body><div id="hero" data-bg="' . $large . '" style="width:400px;height:200px"></div></body></html>';
list($data_output, $data_links) = transform_html($data_bg);
check('bare data-bg converted', strpos(first_tag_attribute($data_output, 'DIV', 'data-bg'), '/w0/') === 0 || strpos(first_tag_attribute($data_output, 'DIV', 'data-bg'), '/_faaaster/images/v1/webp/q80/w0/') === 0);
check('data-bg retains bare URL form', strpos(first_tag_attribute($data_output, 'DIV', 'data-bg'), 'url('), false);
check('data-bg does not replace its existing inline style', first_tag_attribute($data_output, 'DIV', 'style'), 'width:400px;height:200px');
check('adaptive data-bg waits for loader style mutation', strpos($data_output, '[style*=') !== false);
check('data-bg does not add a competing lazy-loader', strpos($data_output, 'IntersectionObserver'), false);
$data_layers = str_replace($large, esc_attr('url("' . $large . '"), url("' . $small . '")'), $data_bg);
list($data_layers_output) = transform_html($data_layers);
check('data-bg CSS layer list converted', substr_count(first_tag_attribute($data_layers_output, 'DIV', 'data-bg'), '/w0/'), 2);
check('data-bg layer list adapts with independent variables', substr_count($data_layers_output, 'background-image:var('), 1);
list($data_cold) = transform_html($data_bg, $empty);
check('data-bg without measures uses w0', strpos(first_tag_attribute($data_cold, 'DIV', 'data-bg'), '/w0/') !== false);
check('data-bg without measures has no density rule', strpos($data_cold, 'dppx'), false);

// data-bg is author text (Contributor/Author pass it through wp_kses_post) copied into a
// generated STYLE element: nothing around our url() replacements may close or escape it.
$data_bg_attacks = array(
    'closing STYLE tag' => 'url(' . $large . ') </style><script>alert(document.domain)</script>',
    'declaration break-out' => 'url(' . $large . ');}body{display:none',
    'block break-out' => 'url(' . $large . ')}*{color:red',
    'comment' => 'url(' . $large . ') /* x */',
    'at-rule' => 'url(' . $large . '), @import x',
    'CSS escape' => 'url(' . $large . ') \\3c /style\\3e ',
    'quoted string' => 'url(' . $large . '), "</style>"',
    'unbalanced parenthesis' => 'url(' . $large . '), rgb(0,0,0',
    'stray closing parenthesis' => 'url(' . $large . ')), rgb(0,0,0)',
    'important injection' => 'url(' . $large . ') !important',
    'unresolved external URL' => 'url(' . $large . '), url(https://evil.example/x.png)',
);
foreach ($data_bg_attacks as $name => $value) {
    $document = str_replace('data-bg="' . $large . '"', 'data-bg="' . esc_attr($value) . '"', $data_bg);
    list($attack_output) = transform_html($document);
    // An unsafe value leaves the element exactly as authored: no generated STYLE, no rewrite.
    check('data-bg attack generates no STYLE: ' . $name, strpos($attack_output, 'data-faaaster-images='), false);
    check('data-bg attack leaves no executable markup: ' . $name, strpos($attack_output, '<script>alert'), false);
    check('data-bg attack element not taken over: ' . $name, first_tag_attribute($attack_output, 'DIV', 'data-faaaster-bg'), null);
    check('data-bg attack keeps the authored attribute: ' . $name, first_tag_attribute($attack_output, 'DIV', 'data-bg'), $value);
}
$gradient = 'url("' . $large . '"), linear-gradient(90deg, rgba(0,0,0,.5) 0%, #fff 100%)';
list($gradient_output) = transform_html(str_replace('data-bg="' . $large . '"', 'data-bg="' . esc_attr($gradient) . '"', $data_bg));
check('data-bg gradient layer still adapted', substr_count($gradient_output, 'background-image:var('), 1);
check('data-bg gradient layer kept in adaptive CSS', strpos($gradient_output, 'linear-gradient(90deg, rgba(0,0,0,.5) 0%, #fff 100%)!important') !== false);

$important = str_replace(')">', ')!important">', $adaptive);
list($important_output) = transform_html($important);
check('inline important preserved', strpos(first_tag_attribute($important_output, 'DIV', 'style'), '!important') !== false);
$overridden = str_replace('background-image:url(', 'background-image:url(' . $small . ');background-image:url(', $adaptive);
list($overridden_output, $overridden_links) = transform_html($overridden);
check('overridden background never preloaded', strpos($overridden_links, 'small.png'), false);
check('only effective declaration rewritten', substr_count(first_tag_attribute($overridden_output, 'DIV', 'style'), '/w0/'), 1);
$important_first = str_replace(');background-image:', ')!important; background-image:', $overridden);
list($important_first_output, $important_first_links) = transform_html($important_first);
check('important beats later normal background', strpos($important_first_links, 'large.png'), false);
check('important effective source retained', strpos($important_first_links, 'small.png') !== false);
$duplicate_ids = '<html><head></head><body><div id="same" style="background-image:url(' . $large . ')"></div>'
    . '<div id="same" style="background-image:url(' . $small . ')"></div></body></html>';
list($duplicates_output) = transform_html($duplicate_ids);
$duplicate_parser = new OD_HTML_Tag_Processor($duplicates_output);
$markers = array();
while ($duplicate_parser->next_tag('DIV')) { $markers[] = $duplicate_parser->get_attribute('data-faaaster-bg'); }
check('repeated builder IDs cannot share a background variable scope', count(array_unique($markers)), 2);

transform_html($adaptive, null, 20);
$dimensions = get_post_meta(20, FaaasterImageBackgrounds::SOURCES_META, true);
check('one source snapshot contains only XPath and dimensions', array_values($dimensions), array(array(array('width' => 1600, 'height' => 800))));
$writes = $GLOBALS['meta_writes'][FaaasterImageBackgrounds::SOURCES_META];
transform_html($adaptive, collection_for($adaptive, 'hero', 1, array(400 => 400.9)), 20);
check('unchanged sources do not write metadata again', $GLOBALS['meta_writes'][FaaasterImageBackgrounds::SOURCES_META], $writes);
check('finish hook is scoped to its template', has_action('od_finish_template_optimization'), false);
transform_html($adaptive, null, null);
check('first visit without an OD post does not write an invalid ID', $GLOBALS['meta_writes'][FaaasterImageBackgrounds::SOURCES_META], $writes);
transform_html(str_replace($large, $small, $adaptive), null, 21);
check('source snapshot is isolated per OD URL', get_post_meta(20, FaaasterImageBackgrounds::SOURCES_META, true), $dimensions);
transform_html(str_replace($large, $small, $adaptive), null, 20);
check('changed dimensions update their single snapshot', array_values(get_post_meta(20, FaaasterImageBackgrounds::SOURCES_META, true)), array(array(array('width' => 400, 'height' => 200))));
transform_html($known, null, 21);
check('removed backgrounds clear the old source snapshot', get_post_meta(21, FaaasterImageBackgrounds::SOURCES_META, true), array());
transform_html($known, null, 22);
check('pages without backgrounds never create source metadata', get_post_meta(22, FaaasterImageBackgrounds::SOURCES_META, true), '');
transform_html($adaptive, null, 20);
$jitter_metrics_a = collection_for($adaptive, 'hero', 1, array(400 => array('width' => 400.1, 'height' => 200.1), 1200 => 400.1));
$jitter_metrics_b = collection_for($adaptive, 'hero', 1, array(400 => array('width' => 400.9, 'height' => 200.9), 1200 => 400.9));
check('decimal geometry does not alter purge decisions', FaaasterImagePurges::fingerprint($jitter_metrics_a, 20), FaaasterImagePurges::fingerprint($jitter_metrics_b, 20));
check('background bucket crossing invalidates HTML decisions', FaaasterImagePurges::fingerprint(collection_for($adaptive, 'hero', 1, array(400 => 479.9)), 20)
    !== FaaasterImagePurges::fingerprint(collection_for($adaptive, 'hero', 1, array(400 => 480.1)), 20));
check('cover height bucket crossing invalidates HTML decisions', FaaasterImagePurges::fingerprint(collection_for($adaptive, 'hero', 1, array(400 => array('width' => 390, 'height' => 240))), 20)
    !== FaaasterImagePurges::fingerprint(collection_for($adaptive, 'hero', 1, array(400 => array('width' => 390, 'height' => 240.1))), 20));
$normalized = FaaasterImageBackgrounds::decisions($jitter_metrics_a, $dimensions, FaaasterImageOptimization::resize_widths());
check('fingerprint stores the same admitted base used by CSS', array_values($normalized[0]['widths']), array(array(480)));
$writes = $GLOBALS['meta_writes'][FaaasterImageBackgrounds::SOURCES_META];
FaaasterImagePurges::fingerprint($jitter_metrics_b, 20);
check('recording decisions never rewrites source metadata', $GLOBALS['meta_writes'][FaaasterImageBackgrounds::SOURCES_META], $writes);
$purge_context = (object) array('url_metrics_id' => 20, 'url_metric_group_collection' => $jitter_metrics_a,
    'url_metric' => $jitter_metrics_a->get_flattened_url_metrics()[0],
    'request' => new class { public function get_param($name) { return $name === 'faaaster_parser_revision' ? 1 : 4242; } });
$purge_count = count(faaaster_cache()->queue()->urls);
FaaasterImagePurges::stored($purge_context);
FaaasterImagePurges::flush(4242);
check('first background decision purges once', count(faaaster_cache()->queue()->urls), $purge_count + 1);
$purge_context->url_metric_group_collection = $jitter_metrics_b;
FaaasterImagePurges::stored($purge_context);
FaaasterImagePurges::flush(4242);
check('refreshed decimal measurements enqueue no purge', get_post_meta(4242, FaaasterImagePurges::PENDING, true), array());
check('refreshed decimal measurements cause no transport call', count(faaaster_cache()->queue()->urls), $purge_count + 1);
check('REST storage never updates source dimensions', $GLOBALS['meta_writes'][FaaasterImageBackgrounds::SOURCES_META], $writes);
$purge_context->url_metric_group_collection = collection_for($adaptive, 'hero', 1, array(400 => 480.1));
FaaasterImagePurges::stored($purge_context);
FaaasterImagePurges::flush(4242);
check('changed admitted bucket stays pending during cooldown', isset(get_post_meta(4242, FaaasterImagePurges::PENDING, true)[20]));
$GLOBALS['meta'][20][FaaasterImagePurges::LAST] = time() - 601;
FaaasterImagePurges::flush(4242);
check('changed admitted bucket eventually purges', count(faaaster_cache()->queue()->urls), $purge_count + 2);
check('image geometry alone does not trigger background purge', FaaasterImagePurges::fingerprint(collection_for($known, 'hero', 1, array(400 => 300)))
    === FaaasterImagePurges::fingerprint(collection_for($known, 'hero', 1, array(400 => 320))));
$nested_image = str_replace(array('<body>', '</body>'), array('<body><main><div><span></span>', '</div></main></body>'), $known);
$nested_metrics = collection_for($nested_image);
check('nested fixture uses real OD self::IMG XPath', strpos(key($nested_metrics->get_first_group()->get_xpath_elements_map()), '[self::IMG]') !== false);
check('nested image geometry does not purge', FaaasterImagePurges::fingerprint(collection_for($nested_image, 'hero', 1, array(400 => 300.1)))
    === FaaasterImagePurges::fingerprint(collection_for($nested_image, 'hero', 1, array(400 => 310.9))));
$nested_xpath = key($nested_metrics->get_first_group()->get_xpath_elements_map());
check('real nested IMG XPath is excluded even with stale background metadata',
    FaaasterImageBackgrounds::decisions($nested_metrics, array($nested_xpath => array(array('width' => 1600, 'height' => 800))), FaaasterImageOptimization::resize_widths())[0]['widths'], array());
list($unknown_lazy) = transform_html(str_replace('id="hero"', 'id="below"', $unknown));
check('unknown lazy image gets only a full-size density candidate', strpos(first_tag_attribute($unknown_lazy, 'IMG', 'srcset'), '/w0/') !== false);
check('unknown lazy image does not invent sizes', first_tag_attribute($unknown_lazy, 'IMG', 'sizes'), null);

$original_src = first_tag_attribute($known_output, 'IMG', 'src');
check('IMG src remains authored', $original_src, $large);
$big_image = str_replace($large, $wide, $known);
list($big_image_output) = transform_html($big_image);
check('full-size candidate beyond resize limit', strpos(first_tag_attribute($big_image_output, 'IMG', 'srcset'), 'wide.png 3000w') !== false);
check('full-size descriptor never creates w3000 URL', strpos($big_image_output, '/w3000/'), false);
$sized_picture = str_replace('<img id="hero"', '<img width="400" id="hero"', $picture);
list($sized_picture_output) = transform_html($sized_picture);
check('picture source sizing follows known IMG width', first_tag_attribute($sized_picture_output, 'SOURCE', 'sizes'), '(max-width: 400px) 100vw, 400px');
check('picture fallback src remains authored', first_tag_attribute($sized_picture_output, 'IMG', 'src'), $large);

$browser_fixtures = array();
foreach (array('img' => $known, 'img-density' => $unknown, 'picture' => $picture, 'background' => $adaptive,
    'background-lazy' => str_replace(array('id="hero"', 'width:400px'), array('id="below"', 'margin-top:3000px;width:400px'), $adaptive),
    'background-lazy-important' => str_replace(array('id="hero"', 'width:400px'), array('id="below"', 'margin-top:3000px;width:400px'), $important),
    'background-overridden' => str_replace('background-image:url(', 'background-image:url(' . $small . ');background-image:url(', $adaptive),
    'background-important' => $important, 'background-layers' => $layers, 'data-bg' => $data_bg,
    'data-bg-layers' => $data_layers, 'data-bg-dormant' => str_replace('id="hero"', 'id="below"', $data_bg),
    'background-cold' => $adaptive) as $name => $document) {
    list($page, $links, $header) = transform_html($document, $name === 'background-cold' ? $empty : null);
    $browser_fixtures[$name] = array('html' => str_replace('</head>', $links . '</head>', $page), 'link' => $header);
}
$group_background = str_replace('</head>', '<style>@media(max-width:480px){#hero{width:300px!important;height:150px!important}}</style></head>', $adaptive);
list($page, $links, $header) = transform_html($group_background, collection_for($group_background, 'hero', 1, array(400 => array('width' => 300, 'height' => 150), 1200 => 400)));
$browser_fixtures['background-groups'] = array('html' => str_replace('</head>', $links . '</head>', $page), 'link' => $header);
$cover_source = image_png(1920, 1080, 'cover.png');
$cover_background = str_replace(array('width:400px;height:200px', $large), array('width:390px;height:844px;background-size:cover', $cover_source), $adaptive);
$cover_collection = collection_for($cover_background, 'hero', 1, array(400 => array('width' => 390, 'height' => 844), 1200 => array('width' => 390, 'height' => 844)));
list($page, $links, $header) = transform_html($cover_background, $cover_collection);
check('cover hero does not request element-width buckets', strpos($page, '/w1280/'), false);
check('cover hero starts at full painted CSS width', strpos($page, '/w1536/') !== false);
$browser_fixtures['background-cover'] = array('html' => str_replace('</head>', $links . '</head>', $page), 'link' => $header);
