<?php

$optional_documents = array(
    'paragraph parent end' => '<div><p>Text</div>',
    'paragraph next block' => '<p>Text<div>Block</div>',
    'paragraph next paragraph' => '<p>One<p>Two</p>',
    'paragraph inside list items' => '<ul><li><p>One<li><p>Two</ul>',
    'list siblings and nested list' => '<ul><li id="outer">Outer<ul><li>Inner one<li>Inner two</ul><li id="last">Last</ul>',
    'ordered list' => '<ol><li>One<li>Two</ol>',
    'option siblings' => '<select><option>One<option>Two</select>',
    'option groups' => '<select><optgroup label="one"><option>One<option>Two<optgroup label="two"><option>Three</select>',
    'table cells and rows' => '<table><tbody><tr><td>One<td>Two<tr><td>Three</table>',
    'table header and body' => '<table><thead><tr><th>Head<th>Head two<tbody><tr><td>Body<td>Body two</table>',
    'table without authored body' => '<table><tr><td>One<td>Two<tr><td>Three</table>',
    'nested tables' => '<table><tr><td><table><tr><td>Inner one<td>Inner two</table><td>Outer two</table>',
);
foreach ($optional_documents as $label => $content) {
    $document = '<html><head></head><body><main id="main">' . $content . '<img><img></main></body></html>';
    $p = new OD_HTML_Tag_Processor($document);
    $script = '<script id="optional-injection-probe"></script>';
    $p->append_body_html($script);
    $images = array();
    $ids = array();
    while ($p->next_tag(array('tag_closers' => 'skip'))) {
        if ($p->get_tag() === 'IMG') { $images[] = $p->get_xpath(); }
        if ($p->get_attribute('id')) { $ids[$p->get_attribute('id')] = $p->get_breadcrumbs(); }
        $p->set_bookmark('optional-probe');
        $before = array($p->get_xpath(), $p->get_breadcrumbs());
        $p->seek('optional-probe');
        check('optional closure bookmarks restore XPath and stack: ' . $label, array($p->get_xpath(), $p->get_breadcrumbs()), $before);
        $p->release_bookmark('optional-probe');
    }
    $output = $p->get_updated_html();
    check('optional closures allow verified injection: ' . $label, $p->was_body_html_injected($script));
    check('optional closures keep the customer HTML: ' . $label, str_replace($script, '', $output), $document);
    $first = in_array($label, array('paragraph next block', 'paragraph next paragraph'), true) ? 3 : 2;
    check('optional closure image siblings: ' . $label, $images, array("/HTML/BODY/MAIN[@id='main']/*[" . $first . "][self::IMG]", "/HTML/BODY/MAIN[@id='main']/*[" . ($first + 1) . "][self::IMG]"));
    if ($label === 'list siblings and nested list') {
        check('inner list does not close an outer item', $ids['outer'], array('HTML', 'BODY', 'MAIN', 'UL', 'LI'));
        check('next outer item is a sibling', $ids['last'], $ids['outer']);
    }
    // Native optimizer must also insert its real detection scripts on these documents.
    $optional_native_documents[$label] = str_replace('<img><img>', '<img src="' . $large . '" width="400">', $document);
}
foreach (array(
    '<div><li>One<li>Two</li></div>',
    '<div><tr>One<tr>Two</tr></div>',
    '<div><option>One<option>Two</option></div>',
    '<x-card><p>Text</x-card>',
) as $content) {
    $p = new OD_HTML_Tag_Processor('<html><head></head><body>' . $content . '</body></html>');
    $p->append_body_html('<script id="invalid-optional-probe"></script>');
    while ($p->next_tag(array('tag_closers' => 'skip'))) {}
    check('wrong optional parent remains ambiguous', strpos($p->get_updated_html(), 'invalid-optional-probe'), false);
}
