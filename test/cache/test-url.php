<?php

fc_section('url');

$p = FaaasterCacheUrl::parse('https://Example.COM:443/Blog/Mon-Article/');
fc_check('host lowercased, port dropped', $p['host'], 'example.com');
fc_check('path kept (case-sensitive)', $p['path'], '/Blog/Mon-Article/');

$p = FaaasterCacheUrl::parse('https://example.com');
fc_check('empty path becomes /', $p['path'], '/');
fc_check('home purges /', FaaasterCacheUrl::purge_paths($p, '/'), array('/'));

$p = FaaasterCacheUrl::parse('https://example.com/blog/');
fc_check('subdirectory home also purges index.php', FaaasterCacheUrl::purge_paths($p, '/blog/'), array('/blog/', '/blog/index.php'));
fc_check('subdirectory home without trailing slash', FaaasterCacheUrl::purge_paths(FaaasterCacheUrl::parse('https://example.com/blog'), '/blog/'), array('/blog', '/blog/index.php'));

$p = FaaasterCacheUrl::parse('https://example.com/shop/?orderby=price');
fc_check('query string: no nginx purge', FaaasterCacheUrl::purge_paths($p, '/'), array());
fc_check('query string kept in url (for Cloudflare)', $p['url'], 'https://example.com/shop/?orderby=price');

$p = FaaasterCacheUrl::parse('https://example.com/wp-content/uploads/elementor/css/post-12.css');
fc_check('static file: no nginx purge', FaaasterCacheUrl::purge_paths($p, '/'), array());

$p = FaaasterCacheUrl::parse('https://example.com/café/été');
fc_check('non-ASCII percent-encoded', $p['path'], '/caf%C3%A9/%C3%A9t%C3%A9');
$p = FaaasterCacheUrl::parse('https://example.com/caf%C3%A9/');
fc_check('existing %XX untouched', $p['path'], '/caf%C3%A9/');

fc_check('ftp rejected', FaaasterCacheUrl::parse('ftp://example.com/x'), null);
fc_check('relative rejected', FaaasterCacheUrl::parse('/contact/'), null);
fc_check('garbage rejected', FaaasterCacheUrl::parse(''), null);

fc_check('cloudflare host allowed', FaaasterCacheUrl::host_allowed('EXAMPLE.com', array('example.com')), true);
fc_check('cloudflare foreign host refused', FaaasterCacheUrl::host_allowed('evil.test', array('example.com')), false);
