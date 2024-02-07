<?php
/**
 * PrivateBin
 *
 * a zero-knowledge paste bin
 *
 * @link      https://github.com/PrivateBin/PrivateBin
 * @copyright 2012 Sébastien SAUVAGE (sebsauvage.net)
 * @license   https://www.opensource.org/licenses/zlib-license.php The zlib/libpng License
 * @version   1.5.1
 */

namespace PrivateBin;

use Exception;
use PrivateBin\Persistence\ServerSalt;
use PrivateBin\Persistence\TrafficLimiter;

/**
 * Controller
 *
 * Puts it all together.
 */
class Controller
{
    /**
     * version
     *
     * @const string
     */
    public const VERSION = '1.5.1';

    /**
     * minimal required PHP version
     *
     * @const string
     */
    public const MIN_PHP_VERSION = '5.6.0';

    /**
     * show the same error message if the paste expired or does not exist
     *
     * @const string
     */
    public const GENERIC_ERROR = 'Paste does not exist, has expired or has been deleted.';

    /**
     * configuration
     *
     * @access private
     * @var    Configuration
     */
    private $_conf;

    /**
     * error message
     *
     * @access private
     * @var    string
     */
    private $_error = '';

    /**
     * status message
     *
     * @access private
     * @var    string
     */
    private $_status = '';

    /**
     * JSON message
     *
     * @access private
     * @var    string
     */
    private $_json = '';

    /**
     * Factory of instance models
     *
     * @access private
     * @var    model
     */
    private $_model;

    /**
     * request
     *
     * @access private
     * @var    request
     */
    private $_request;

    /**
     * URL base
     *
     * @access private
     * @var    string
     */
    private $_urlBase;

    /**
     * constructor
     *
     * initializes and runs PrivateBin
     *
     * @access public
     * @throws Exception
     */
    public function __construct()
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION) < 0) {
            throw new Exception(I18n::_('%s requires php %s or above to work. Sorry.', I18n::_('PrivateBin'), self::MIN_PHP_VERSION), 1);
        }

        // load config from ini file, initialize required classes
        $this->_init();

        switch ($this->_request->getOperation()) {
            case 'create':
                $this->_create();
                break;
            case 'delete':
                $this->_delete(
                    $this->_request->getParam('pasteid'),
                    $this->_request->getParam('deletetoken')
                );
                break;
            case 'read':
                $this->_read($this->_request->getParam('pasteid'));
                break;
            case 'jsonld':
                $this->_jsonld($this->_request->getParam('jsonld'));
                return;
            case 'yourlsproxy':
                $this->_yourlsproxy($this->_request->getParam('link'));
                break;
        }

        // output JSON or HTML
        if ($this->_request->isJsonApiCall()) {
            header('Content-type: ' . Request::MIME_JSON);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
            header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');
            echo $this->_json;
        } else {
            $this->_view();
        }
    }

    /**
     * initialize PrivateBin
     *
     * @access private
     * @throws Exception
     */
    private function _init()
    {
        $this->_conf = new Configuration();
        $this->_model = new Model($this->_conf);
        $this->_request = new Request();
        $this->_urlBase = $this->_request->getRequestUri();

        // set default language
        $lang = $this->_conf->getKey('languagedefault');
        I18n::setLanguageFallback($lang);
        // force default language, if language selection is disabled and a default is set
        if (!$this->_conf->getKey('languageselection') && strlen($lang) == 2) {
            $_COOKIE['lang'] = $lang;
            setcookie('lang', $lang, 0, '', '', true);
        }
    }

    /**
     * Store new paste or comment
     *
     * POST contains one or both:
     * data = json encoded FormatV2 encrypted text (containing keys: iv,v,iter,ks,ts,mode,adata,cipher,salt,ct)
     * attachment = json encoded FormatV2 encrypted text (containing keys: iv,v,iter,ks,ts,mode,adata,cipher,salt,ct)
     *
     * All optional data will go to meta information:
     * expire (optional) = expiration delay (never,5min,10min,1hour,1day,1week,1month,1year,burn) (default:never)
     * formatter (optional) = format to display the paste as (plaintext,syntaxhighlighting,markdown) (default:syntaxhighlighting)
     * burnafterreading (optional) = if this paste may only viewed once ? (0/1) (default:0)
     * opendiscusssion (optional) = is the discussion allowed on this paste ? (0/1) (default:0)
     * attachmentname = json encoded FormatV2 encrypted text (containing keys: iv,v,iter,ks,ts,mode,adata,cipher,salt,ct)
     * nickname (optional) = in discussion, encoded FormatV2 encrypted text nickname of author of comment (containing keys: iv,v,iter,ks,ts,mode,adata,cipher,salt,ct)
     * parentid (optional) = in discussion, which comment this comment replies to.
     * pasteid (optional) = in discussion, which paste this comment belongs to.
     *
     * @access private
     * @return void
     * @throws Exception
     * @throws Exception
     */
    private function _create(): void
    {
        // Ensure last paste from visitors IP address was more than configured amount of seconds ago.
        ServerSalt::setStore($this->_model->getStore());
        TrafficLimiter::setConfiguration($this->_conf);
        TrafficLimiter::setStore($this->_model->getStore());
        try {
            TrafficLimiter::canPass();
        } catch (Exception $e) {
            $this->_return_message(1, $e->getMessage());
            return;
        }

        $data = $this->_request->getData();
        $isComment = array_key_exists('pasteid', $data) &&
            !empty($data['pasteid']) &&
            array_key_exists('parentid', $data) &&
            !empty($data['parentid']);
        if (!FormatV2::isValid($data, $isComment)) {
            $this->_return_message(1, I18n::_('Invalid data.'));
            return;
        }
        try {
            $sizelimit = $this->_conf->getKey('sizelimit');
        } catch (Exception $e) {
        }
        // Ensure content is not too big.
        if (strlen($data['ct']) > $sizelimit) {
            $this->_return_message(
                1,
                I18n::_(
                    'Paste is limited to %s of encrypted data.',
                    Filter::formatHumanReadableSize($sizelimit)
                )
            );
            return;
        }

        // The user posts a comment.
        if ($isComment) {
            $paste = $this->_model->getPaste($data['pasteid']);
            if ($paste->exists()) {
                try {
                    $comment = $paste->getComment($data['parentid']);
                    $comment->setData($data);
                    $comment->store();
                } catch (Exception $e) {
                    $this->_return_message(1, $e->getMessage());
                    return;
                }
                $this->_return_message(0, $comment->getId());
            } else {
                $this->_return_message(1, I18n::_('Invalid data.'));
            }
        } // The user posts a standard paste.
        else {
            $this->_model->purge();
            $paste = $this->_model->getPaste();
            try {
                $paste->setData($data);
                $paste->store();
            } catch (Exception $e) {
                $this->_return_message(1, $e->getMessage());
                return;
            }
            $this->_return_message(0, $paste->getId(), ['deletetoken' => $paste->getDeleteToken()]);
        }
    }

    /**
     * Delete an existing paste
     *
     * @access private
     * @param string $dataid
     * @param string $deletetoken
     */
    private function _delete($dataid, $deletetoken)
    {
        try {
            $paste = $this->_model->getPaste($dataid);
            if ($paste->exists()) {
                // accessing this method ensures that the paste would be
                // deleted if it has already expired
                $paste->get();
                if (hash_equals($paste->getDeleteToken(), $deletetoken)) {
                    // Paste exists and deletion token is valid: Delete the paste.
                    $paste->delete();
                    $this->_status = 'Paste was properly deleted.';
                } else {
                    $this->_error = 'Wrong deletion token. Paste was not deleted.';
                }
            } else {
                $this->_error = self::GENERIC_ERROR;
            }
        } catch (Exception $e) {
            $this->_error = $e->getMessage();
        }
        if ($this->_request->isJsonApiCall()) {
            if (strlen($this->_error)) {
                $this->_return_message(1, $this->_error);
            } else {
                $this->_return_message(0, $dataid);
            }
        }
    }

    /**
     * Read an existing paste or comment, only allowed via a JSON API call
     *
     * @access private
     * @param string $dataid
     */
    private function _read($dataid)
    {
        if (!$this->_request->isJsonApiCall()) {
            return;
        }

        try {
            $paste = $this->_model->getPaste($dataid);
            if ($paste->exists()) {
                $data = $paste->get();
                if (array_key_exists('salt', $data['meta'])) {
                    unset($data['meta']['salt']);
                }
                $this->_return_message(0, $dataid, $data);
            } else {
                $this->_return_message(1, self::GENERIC_ERROR);
            }
        } catch (Exception $e) {
            $this->_return_message(1, $e->getMessage());
        }
    }

    /**
     * Display frontend.
     *
     * @access private
     */
    private function _view()
    {
        // set headers to disable caching
        $time = gmdate('D, d M Y H:i:s \G\M\T');
        header('Cache-Control: no-store, no-cache, no-transform, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: ' . $time);
        header('Last-Modified: ' . $time);
        header('Vary: Accept');
        try {
            header('Content-Security-Policy: ' . $this->_conf->getKey('cspheader'));
        } catch (Exception $e) {
        }
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cross-Origin-Embedder-Policy: require-corp');
        // disabled, because it prevents links from a paste to the same site to
        // be opened. Didn't work with `same-origin-allow-popups` either.
        // See issue https://github.com/PrivateBin/PrivateBin/issues/970 for details.
        // header('Cross-Origin-Opener-Policy: same-origin');
        header('Permissions-Policy: browsing-topics=()');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: deny');
        header('X-XSS-Protection: 1; mode=block');

        // label all the expiration options
        $expire = [];
        try {
            foreach ($this->_conf->getSection('expire_options') as $time => $seconds) {
                try {
                    $expire[$time] = ($seconds == 0) ? I18n::_(ucfirst($time)) : Filter::formatHumanReadableTime($time);
                } catch (Exception $e) {
                }
            }
        } catch (Exception $e) {
        }

        // translate all the formatter options
        try {
            $formatters = array_map('PrivateBin\\I18n::_', $this->_conf->getSection('formatter_options'));
        } catch (Exception $e) {
        }

        // set language cookie if that functionality was enabled
        $languageselection = '';
        try {
            if ($this->_conf->getKey('languageselection')) {
                $languageselection = I18n::getLanguage();
                setcookie('lang', $languageselection, 0, '', '', true);
            }
        } catch (Exception $e) {
        }

        // strip policies that are unsupported in meta tag
        try {
            $metacspheader = str_replace(
                [
                    'frame-ancestors \'none\'; ',
                    '; sandbox allow-same-origin allow-scripts allow-forms allow-popups allow-modals allow-downloads',
                ],
                '',
                $this->_conf->getKey('cspheader')
            );
        } catch (Exception $e) {
        }

        $page = new View();
        $page->assign('CSPHEADER', $metacspheader);
        $page->assign('ERROR', I18n::_($this->_error));
        try {
            $page->assign('NAME', $this->_conf->getKey('name'));
        } catch (Exception $e) {
        }
        if ($this->_request->getOperation() === 'yourlsproxy') {
            $page->assign('SHORTURL', $this->_status);
            try {
                $page->draw('yourlsproxy');
            } catch (Exception $e) {
            }
            return;
        }
        try {
            $page->assign('BASEPATH', I18n::_($this->_conf->getKey('basepath')));
        } catch (Exception $e) {
        }
        $page->assign('STATUS', I18n::_($this->_status));
        $page->assign('VERSION', self::VERSION);
        try {
            $page->assign('DISCUSSION', $this->_conf->getKey('discussion'));
        } catch (Exception $e) {
        }
        try {
            $page->assign('OPENDISCUSSION', $this->_conf->getKey('opendiscussion'));
        } catch (Exception $e) {
        }
        $page->assign('MARKDOWN', array_key_exists('markdown', $formatters));
        $page->assign('SYNTAXHIGHLIGHTING', array_key_exists('syntaxhighlighting', $formatters));
        try {
            $page->assign('SYNTAXHIGHLIGHTINGTHEME', $this->_conf->getKey('syntaxhighlightingtheme'));
        } catch (Exception $e) {
        }
        $page->assign('FORMATTER', $formatters);
        try {
            $page->assign('FORMATTERDEFAULT', $this->_conf->getKey('defaultformatter'));
        } catch (Exception $e) {
        }
        try {
            $page->assign('INFO', I18n::_(str_replace("'", '"', $this->_conf->getKey('info'))));
        } catch (Exception $e) {
        }
        try {
            $page->assign('NOTICE', I18n::_($this->_conf->getKey('notice')));
        } catch (Exception $e) {
        }
        try {
            $page->assign('BURNAFTERREADINGSELECTED', $this->_conf->getKey('burnafterreadingselected'));
        } catch (Exception $e) {
        }
        try {
            $page->assign('PASSWORD', $this->_conf->getKey('password'));
        } catch (Exception $e) {
        }
        try {
            $page->assign('FILEUPLOAD', $this->_conf->getKey('fileupload'));
        } catch (Exception $e) {
        }
        try {
            $page->assign('ZEROBINCOMPATIBILITY', $this->_conf->getKey('zerobincompatibility'));
        } catch (Exception $e) {
        }
        $page->assign('LANGUAGESELECTION', $languageselection);
        $page->assign('LANGUAGES', I18n::getLanguageLabels(I18n::getAvailableLanguages()));
        $page->assign('EXPIRE', $expire);
        try {
            $page->assign('EXPIREDEFAULT', $this->_conf->getKey('default', 'expire'));
        } catch (Exception $e) {
        }
        try {
            $page->assign('URLSHORTENER', $this->_conf->getKey('urlshortener'));
        } catch (Exception $e) {
        }
        try {
            $page->assign('QRCODE', $this->_conf->getKey('qrcode'));
        } catch (Exception $e) {
        }
        try {
            $page->assign('HTTPWARNING', $this->_conf->getKey('httpwarning'));
        } catch (Exception $e) {
        }
        $page->assign('HTTPSLINK', 'https://' . $this->_request->getHost() . $this->_request->getRequestUri());
        try {
            $page->assign('COMPRESSION', $this->_conf->getKey('compression'));
        } catch (Exception $e) {
        }
        try {
            $page->draw($this->_conf->getKey('template'));
        } catch (Exception $e) {
        }
    }

    /**
     * outputs requested JSON-LD context
     *
     * @access private
     * @param string $type
     */
    private function _jsonld($type)
    {
        if ($type !== 'paste' && $type !== 'comment' &&
            $type !== 'pastemeta' && $type !== 'commentmeta'
        ) {
            $type = '';
        }
        $content = '{}';
        $file = PUBLIC_PATH . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . $type . '.jsonld';
        if (is_readable($file)) {
            $content = str_replace(
                '?jsonld=',
                $this->_urlBase . '?jsonld=',
                file_get_contents($file)
            );
        }

        header('Content-type: application/ld+json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        echo $content;
    }

    /**
     * proxies link to YOURLS, updates status or error with response
     *
     * @access private
     * @param string $link
     */
    private function _yourlsproxy($link)
    {
        $yourls = new YourlsProxy($this->_conf, $link);
        if ($yourls->isError()) {
            $this->_error = $yourls->getError();
        } else {
            $this->_status = $yourls->getUrl();
        }
    }

    /**
     * prepares JSON encoded status message
     *
     * @access private
     * @param int $status
     * @param string $message
     * @param array $other
     * @throws Exception
     * @throws Exception
     */
    private function _return_message($status, $message, $other = [])
    {
        $result = ['status' => $status];
        if ($status) {
            $result['message'] = I18n::_($message);
        } else {
            $result['id'] = $message;
            $result['url'] = $this->_urlBase . '?' . $message;
        }
        $result += $other;
        try {
            $this->_json = Json::encode($result);
        } catch (Exception $e) {
        }
    }
}
