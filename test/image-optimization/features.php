<?php

// Exercise the same live hooks/visitor pipeline with each hot-config cut.
foreach (array(
    array(false, 'default', array(), array()), array('', 'default', array(), array()),
    array('   ', 'default', array(), array()), array(',,,', 'configured', array(), array()),
    array(' SRCSET, ,srcset, LAZY ', 'configured', array('lazy', 'srcset'), array()),
    array('future,unknown,future', 'configured', array(), array('future', 'unknown')),
    array('style_bg', 'configured', array('css_lcp', 'style_bg'), array()),
    array('srcset;lazy', 'invalid', array(), array()), array(str_repeat('a', 201), 'invalid', array(), array()),
    array(null, 'invalid', array(), array()), array(array('srcset'), 'invalid', array(), array()),
    array(true, 'invalid', array(), array()), array("srcset\0", 'invalid', array(), array()),
) as $index => $example) {
    $parsed = FaaasterImageOptimization::parse_disabled_features($example[0]);
    check('feature parsing source ' . $index, $parsed['features_source'], $example[1]);
    check('feature parsing cuts ' . $index, $parsed['features_disabled'], $example[2]);
    check('feature parsing unknowns ' . $index, $parsed['features_ignored'], $example[3]);
}
function feature_attribute($html, $id, $name) {
    $p = new WP_HTML_Tag_Processor($html);
    while ($p->next_tag()) { if ($p->get_attribute('id') === $id) { return $p->get_attribute($name); } }
    throw new RuntimeException('Missing feature fixture element ' . $id);
}
$feature_document = str_replace('</body>', '<div id="inline" style="background-image:url(' . $large . ')"></div>'
    . '<div id="data" data-bg="url(' . $large . ')"></div>'
    . '<img id="normal" width="400" src="' . $large . '">'
    . '<img id="lazy" data-src="' . $large . '">'
    . '<picture><source id="picture-source" srcset="' . $large . ' 1600w"><img width="400" src="' . $large . '"></picture>'
    . '<picture><source id="lazy-source" data-srcset="' . $large . ' 1600w"><img data-src="' . $large . '"></picture></body>', $css_document);
$base_etag = FaaasterImageOptimization::etag_data(array());
foreach (array('srcset', 'inline_bg', 'style_bg', 'css_lcp', 'lazy', implode(',', FaaasterImageOptimization::FEATURES)) as $cut) {
    $_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] = $cut;
    FaaasterImageOptimization::initialize();
    $state = faaaster_image_optimization_state();
    $expected = FaaasterImageOptimization::parse_disabled_features($cut);
    foreach ($expected as $key => $value) { check('effective ' . $key . ' for ' . $cut, $state[$key], $value); }
    check('ETag changes on cut ' . $cut, FaaasterImageOptimization::etag_data(array()) !== $base_etag);
    $enabled = $state['features'];
    list($page) = transform_html($feature_document, $css_collection, 0);
    check('normal IMG cut ' . $cut, feature_attribute($page, 'normal', 'srcset') !== null, in_array('srcset', $enabled, true));
    check('normal PICTURE cut ' . $cut, strpos(feature_attribute($page, 'picture-source', 'srcset'), '/_faaaster/') !== false, in_array('srcset', $enabled, true));
    check('lazy IMG cut ' . $cut, feature_attribute($page, 'lazy', 'data-srcset') !== null, in_array('lazy', $enabled, true));
    check('lazy PICTURE cut ' . $cut, strpos(feature_attribute($page, 'lazy-source', 'data-srcset'), '/_faaaster/') !== false, in_array('lazy', $enabled, true));
    check('inline BG cut ' . $cut, strpos(feature_attribute($page, 'inline', 'style'), '/_faaaster/') !== false, in_array('inline_bg', $enabled, true));
    check('data BG cut ' . $cut, strpos(feature_attribute($page, 'data', 'data-bg'), '/_faaaster/') !== false, in_array('inline_bg', $enabled, true));
    check('STYLE cut ' . $cut, strpos($page, '.hero{width:400px;height:200px;background-image:url("' . $large . '")}') === false, in_array('style_bg', $enabled, true));
    check('CSS adaptation cut ' . $cut, strpos($page, 'var(--faaaster-css-lcp-') !== false, in_array('css_lcp', $enabled, true));
    check('CSS collector hook cut ' . $cut, has_filter('od_extension_module_urls', array('FaaasterImageOptimization', 'detection_modules')) !== false, in_array('css_lcp', $enabled, true));
    check('CSS collector declaration cut ' . $cut, count(apply_filters('od_extension_module_urls', array())), in_array('css_lcp', $enabled, true) ? 2 : 1); // IP's module is retained.
    check('parser remains patched ' . $cut, FaaasterImageOptimization::parser_revision(), 1);
    check('response disabled only with no features ' . $cut, apply_filters('od_can_optimize_response', true), (bool) $enabled);
}
unset($_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES']);
FaaasterImageOptimization::initialize();
$before_purges = faaaster_cache()->queue()->all;
$_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] = 'css_lcp';
FaaasterImageOptimization::initialize();
check('effective feature change enqueues one full purge', faaaster_cache()->queue()->all, $before_purges + 1);
FaaasterImageOptimization::initialize();
check('same features do not enqueue again', faaaster_cache()->queue()->all, $before_purges + 1);
$_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] = 'css_lcp,unknown,css_lcp';
FaaasterImageOptimization::initialize();
check('unknown feature alone does not change HTML or purge', faaaster_cache()->queue()->all, $before_purges + 1);
check('unknown is exposed', faaaster_image_optimization_state()['features_ignored'], array('unknown'));
$_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] = array('invalid');
FaaasterImageOptimization::initialize();
check('invalid list retains all effective features', faaaster_image_optimization_state()['features'], FaaasterImageOptimization::FEATURES);
check('invalid list exposes reason', faaaster_image_optimization_state()['reason'], 'features_invalid');
check('invalid list exposes source', faaaster_image_optimization_state()['features_source'], 'invalid');
$_SERVER['IMAGE_OPTIMIZATION_ENABLED'] = 'false';
check('master switch overrides invalid list', faaaster_image_optimization_state()['features'], array());
$_SERVER['IMAGE_OPTIMIZATION_ENABLED'] = 'true';
unset($_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES']);
putenv('IMAGE_OPTIMIZATION_DISABLED_FEATURES=lazy');
check('container environment is read when server value absent', faaaster_image_optimization_state()['features_disabled'], array('lazy'));
$_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] = '';
check('empty server value overrides container value', faaaster_image_optimization_state()['features'], FaaasterImageOptimization::FEATURES);
$_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] = false;
check('non-string server value is invalid even when false', faaaster_image_optimization_state()['features_source'], 'invalid');
putenv('IMAGE_OPTIMIZATION_DISABLED_FEATURES');
unset($_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES']);
FaaasterImageOptimization::initialize();

// Each valid viewport consensus that cannot adapt adds exactly one reason, no URL history.
$before = faaaster_image_optimization_state()['css_lcp_unmatched'];
transform_html(str_replace('id="hero"', 'id="changed-by-js"', $css_document), $css_collection, 0);
transform_html($duplicate_document, $css_collection, 0);
transform_html(str_replace($large, $small, $css_document), $css_collection, 0);
transform_html($hash_document, $css_collection, 0);
$after = faaaster_image_optimization_state()['css_lcp_unmatched'];
foreach (FaaasterImageOptimization::CSS_LCP_REASONS as $reason) { check('unmatched CSS reason ' . $reason, $after[$reason], $before[$reason] + 2); }
transform_html($css_document, $css_collection, 0);
transform_html($css_document, css_lcp_collection(array()), 0);
$non_consensus = $css_samples;
foreach ($non_consensus as &$sample) { $sample['change'] = array('faaasterCssLcp' => null); }
unset($sample);
transform_html($duplicate_document, css_lcp_collection($non_consensus), 0);
check('success/cold/non-consensus do not increment coverage failures', faaaster_image_optimization_state()['css_lcp_unmatched'], $after);
$_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] = 'css_lcp';
FaaasterImageOptimization::initialize();
transform_html($duplicate_document, $css_collection, 0);
check('disabled CSS LCP does not count unmatched groups', faaaster_image_optimization_state()['css_lcp_unmatched'], $after);
unset($_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES']);
FaaasterImageOptimization::initialize();
