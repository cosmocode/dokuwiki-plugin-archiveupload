<?php
/**
 * Action Plugin ArchiveUpload
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Michael Klier chi@chimeric.de
 */

/**
 * DokuWiki Action Plugin Archive Upload
 *
 * @author Michael Klier <chi@chimeric.de>
 */
class action_plugin_archiveupload extends DokuWiki_Action_Plugin
{

    protected $tmpdir = '';

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('HTML_UPLOADFORM_OUTPUT', 'BEFORE', $this, 'handle_form_output');
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'BEFORE', $this, 'handle_media_upload');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'AFTER', $this, 'metaheaders_after');
    }

    /**
     * Disables new uploader in favor of old
     *
     * @param Doku_Event $event
     * @param $param
     * @author Myron Turner <turnermm02@shaw.ca>
     */
    public function metaheaders_after(Doku_Event $event, $param)
    {
        ptln("\n<script type='text/javascript'>\n //<![CDATA[\n");
        ptln("qq = {};\n //]]>\n</script>");
    }

    /**
     * Adds a checkbox
     *
     * @param Doku_Event $event
     * @param $param
     * @author Michael Klier <chi@chimeric.de>
     */
    public function handle_form_output(Doku_Event $event, $param)
    {
        global $INFO;
        if ($this->getConf('manageronly')) {
            if (!$INFO['isadmin'] && !$INFO['ismanager']) return;
        }
        $event->data->addElement(form_makeCheckboxField('unpack', 0, $this->getLang('unpack')));
    }

    /**
     * MEDIA_UPLOAD_FINISH handler
     *
     * @param Doku_Event $event
     * @param $param
     * @author Michael Klier <chi@chimeric.de>
     */
    public function handle_media_upload(Doku_Event $event, $param)
    {
        global $INFO;

        // nothing todo
        if (!isset($_REQUEST['unpack'])) return;

        // only for managers?
        if ($this->getConf('manageronly')) {
            if (!$INFO['isadmin'] && !$INFO['ismanager']) return;
        }

        // get event data
        list($tmp, $file, $id, $mime) = $event->data;

        // only process known archives
        if (!preg_match('/\.(tar|tar\.gz|tar\.bz2|tgz|tbz|zip)$/', $file)) {
            return;
        }

        // our turn - prevent default action
        $event->preventDefault();

        // extract to tmp directory and copy files over
        try {
            $dir = $this->extractArchive($tmp, $mime);
            $this->postProcessFiles($dir, getNS($id));
            io_rmdir($dir, true);
            msg($this->getLang('decompr_succ'), 1);
        } catch (\Exception $e) {
            msg(hsc($e->getMessage()), -1);
            msg($this->getLang('decompr_err'), -1);
        }
    }

    /**
     * Extract the archive
     *
     * @param string $tmp temporary file path
     * @param string $mime mime type
     * @return string The temporary directory of unpacked files
     * @throws Exception
     * @author Michael Klier <chi@chimeric.de>
     */
    protected function extractArchive($tmp, $mime)
    {
        $dir = io_mktmpdir();
        if (!$dir) {
            throw new \Exception('Failed to create tmp dir, check permissions of tmp/ directory');
        }

        if ($mime === 'application/zip') {
            $archive = new \splitbrain\PHPArchive\Zip();
        } else {
            $archive = new \splitbrain\PHPArchive\Tar();
        }

        $archive->open($tmp);
        $archive->extract($dir);

        // delete the temporary upload
        @unlink($tmp);

        return $dir;
    }

    /**
     * Checks the mime type and fixes the permission and filenames of the
     * extracted files and sends a notification email per uploaded file
     *
     * @param string $source temporary directory where the files where unpacked to
     * @param string $namespace media namespace where the files should go to
     * @author Michael Klier <chi@chimeric.de>
     */
    protected function postProcessFiles($source, $namespace)
    {
        global $conf;
        if ($namespace === false) $namespace = '';

        /** @var \RecursiveDirectoryIterator $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue; // we handle only files and create namespace dirs only when needed
            }

            $id = $namespace . ':' . str_replace('/', ':', $iterator->getSubPathName());
            $id = cleanID($id);
            $fn = mediaFN($id);
            list(, $mime) = mimetype($fn);
            if ($mime === false) continue; // unknown file type
            if (!media_contentcheck($item, $mime)) continue; // bad file
            copy($item, $fn);
            chmod($fn, $conf['fmode']);
            media_notify($id, $fn, $mime);
            // FIXME writing some meta data here would be good
        }
    }
}
