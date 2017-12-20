<?php

/**
 * An image handler which adds support for Visio (*.vsd, *.vsdx) files
 * via libvisio-utils (https://wiki.documentfoundation.org/DLP/Libraries/libvisio)
 *
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @copyright Copyright © 2017 Vitaliy Filippov
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

if (!defined('MEDIAWIKI'))
    die();

// Extension credits that will show up on Special:Version
$wgExtensionCredits['media'][] = array(
    'name'           => 'VisioHandler',
    'version'        => '2017-12-20',
    'author'         => 'Vitaliy Filippov',
    'url'            => 'http://wiki.4intra.net/VisioHandler',
    'descriptionmsg' => 'visiohandler-desc',
);

// Parameters
$wgVisioToXhtml = '/usr/bin/vsd2xhtml';

// Register the media handler
$dir = dirname(__FILE__) . '/';
$wgExtensionMessagesFiles['VisioHandler'] = $dir . 'VisioHandler.i18n.php';
$wgAutoloadClasses['VisioImageHandler'] = $dir . 'VisioImageHandler.php';
$wgAutoloadClasses['SvgThumbnailImage'] = $dir . 'SvgThumbnailImage.php';
$wgMediaHandlers['application/vnd.visio'] = 'VisioImageHandler';
$wgMediaHandlers['application/vnd.ms-visio.drawing'] = 'VisioImageHandler';
if (!in_array('vsd', $wgFileExtensions))
    $wgFileExtensions[] = 'vsd';
if (!in_array('vsdx', $wgFileExtensions))
    $wgFileExtensions[] = 'vsdx';
$wgExtensionFunctions[] = 'egInstallVisioHandlerTypes1_23';
$wgHooks['MimeMagicInit'][] = 'egInstallVisioHandlerTypes';

function egInstallVisioHandlerTypes($mm)
{
    $mm->addExtraTypes(
        "application/vnd.visio vsd\n".
        "application/vnd.ms-visio.drawing vsdx"
    );
    return true;
}

function egInstallVisioHandlerTypes1_23()
{
    global $wgVersion;
    if (version_compare($wgVersion, '1.24', '>='))
        return;
    $mm = MimeMagic::singleton();
    if (empty($mm->mExtToMime['vsd']))
        $mm->mExtToMime['vsd'] = 'application/vnd.visio';
    if (empty($mm->mMimeToExt['application/vnd.visio']))
        $mm->mMimeToExt['application/vnd.visio'] = 'vsd';
    if (empty($mm->mExtToMime['vsdx']))
        $mm->mExtToMime['vsdx'] = 'application/vnd.ms-visio.drawing';
    if (empty($mm->mMimeToExt['application/vnd.ms-visio.drawing']))
        $mm->mMimeToExt['application/vnd.ms-visio.drawing'] = 'vsdx';
}
