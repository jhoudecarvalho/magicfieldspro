<?php
ob_start();

$loaded = false;

// use wp-load. Normally right here, but if it's not...
if( file_exists('../../../wp-load.php') )
{
	require_once('../../../wp-load.php');
	$loaded = true;
} // ...then look over here
elseif( file_exists('./mf-config.php') )
{
	include_once('./mf-config.php');
	require_once(MF_WP_LOAD);
	$loaded = true;
}

if( $loaded !== true ){
	die('Could not load wp-load.php, edit/add mf-config.php and define MF_WP_LOAD to point to a valid wp-load file.');
}

global $mf_domain;

if (!function_exists('mf_send_upload_json')) {
    function mf_send_upload_json($payload) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
        }

        if (function_exists('wp_json_encode')) {
            echo wp_json_encode($payload);
        } else {
            echo json_encode($payload);
        }

        exit;
    }
}

if( !( is_user_logged_in() && current_user_can('upload_files') ) ) {
    mf_send_upload_json(
        array(
            'success' => false,
            'error' => __("You don't have permission to upload files, contact to the administrator for more information!", $mf_domain)
        )
    );
}


/* checking nonce */
$nonce = isset($_GET['nonce_ajax']) ? $_GET['nonce_ajax'] : '';
if (! wp_verify_nonce($nonce, 'once_ajax_uplooad') ){
  mf_send_upload_json(array('success' => false, 'error' => 'Sorry, your nonce did not verify.'));
}

/**
 * Handle file uploads via XMLHttpRequest
 */
class qqUploadedFileXhr {
    /**
     * Save the file to the specified path
     * @return boolean TRUE on success
     */
    function save($path) {    
        $input = fopen("php://input", "r");
        $temp = tmpfile();
        $realSize = stream_copy_to_stream($input, $temp);
        fclose($input);
        
        if ($realSize != $this->getSize()){            
            return false;
        }
        
        $target = fopen($path, "w");        
        fseek($temp, 0, SEEK_SET);
        stream_copy_to_stream($temp, $target);
        fclose($target);
        
        return true;
    }
    function getName() {
        return isset($_GET['qqfile']) ? $_GET['qqfile'] : '';
    }
    function getSize() {
        if (isset($_SERVER["CONTENT_LENGTH"])){
            return (int)$_SERVER["CONTENT_LENGTH"];            
        } else {
            //throw new Exception('Getting content length is not supported.');
            return 0;
        }      
    }   
}

/**
 * Handle file uploads via regular form post (uses the $_FILES array)
 */
class qqUploadedFileForm {  
    /**
     * Save the file to the specified path
     * @return boolean TRUE on success
     */
    function save($path) {
        if(!move_uploaded_file($_FILES['qqfile']['tmp_name'], $path)){
            return false;
        }
        return true;
    }
    function getName() {
        return $_FILES['qqfile']['name'];
    }
    function getSize() {
        return $_FILES['qqfile']['size'];
    }
}

class qqFileUploader {
    var $allowedExtensions = array();
    var $sizeLimit = 0;
    var $file;

    function __construct($allowedExtensions = array(), $sizeLimit = 0){
        $allowedExtensions = array_map("strtolower", $allowedExtensions);
            
        $this->allowedExtensions = $allowedExtensions;        
        $this->sizeLimit = $sizeLimit;       

        if (isset($_GET['qqfile'])) {
            $this->file = new qqUploadedFileXhr();
        } elseif (isset($_FILES['qqfile'])) {
            $this->file = new qqUploadedFileForm();
        } else {
            $this->file = false; 
        }
    }
    
    /**
     * Returns array('success'=>true) or array('error'=>'error message')
     */
    function handleUpload($uploadDirectory, $replaceOldFile = FALSE){
        if (!is_dir($uploadDirectory)) {
            @wp_mkdir_p($uploadDirectory);
        }

        if (!is_writable($uploadDirectory)){
            return array('success' => false, 'error' => "Server error. Upload directory isn't writable.");
        }
        
        if (!$this->file){
            return array('success' => false, 'error' => 'No files were uploaded.');
        }
        
        /*
        $size = $this->file->getSize();
        
        if ($size == 0) {
            return array('error' => 'File is empty');
        }
        
        if ($size > $this->sizeLimit) {
            return array('error' => 'File is too large');
        }
        */
        
        $pathinfo = pathinfo($this->file->getName());
        $filename = isset($pathinfo['filename']) ? $pathinfo['filename'] : '';

        if ($filename === '') {
            return array('success' => false, 'error' => 'Invalid file name.');
        }
        
        // remove any special characters, since these can cause problems
        $special_chars = array (' ','`','"','\'','\\','/'," ","#","$","%","^","&","*","!","~","‘","\"","’","'","=","?","/","[","]","(",")","|","<",">",";","\\",",","+","-");
			  $filename = strtolower(str_replace($special_chars,'', $filename));

        //$filename = md5(uniqid());
        
        // convert the extension to lowercase to avoid problems
        $ext = isset($pathinfo['extension']) ? strtolower($pathinfo['extension']) : '';

        if ($ext === '') {
            return array('success' => false, 'error' => 'File has no extension.');
        }

        if($this->allowedExtensions && !in_array(strtolower($ext), $this->allowedExtensions)){
            $these = implode(', ', $this->allowedExtensions);
            return array('success' => false, 'error' => 'File has an invalid extension, it should be one of '. $these . '.');
        }
        
        if(!$replaceOldFile){
            /// don't overwrite previous files that were uploaded
            while (file_exists($uploadDirectory . $filename . '.' . $ext)) {
                $filename .= rand(10, 99);
            }
        }
        
        if ($this->file->save($uploadDirectory . $filename . '.' . $ext)){
            @chmod($uploadDirectory . $filename . '.' . $ext, 0644);

            $uri = MF_FILES_URI.$filename . '.' . $ext;
            
            return array('success'=>true, 'ext' => $ext, 'thumb' => PHPTHUMB.'?&w=150&h=120&src='.$uri, 'file' => $filename. '.' . $ext ,'uri' => $uri);
        } else {
            return array('success' => false, 'error'=> 'Could not save uploaded file.' .
                'The upload was cancelled, or server error encountered');
        }
        
    }    
}

// list of valid extensions, ex. array("jpeg", "xml", "bmp")
$allowedExtensions = array("pdf", "doc", "xls", "ppt", "txt", "jpeg", "psd", "jpg", "gif", "png", "docx", "pptx", "xslx", "pps", "zip", "gz", "gzip", "mp3", "aac", "mp4", "wav", "wma", "aif", "aiff", "ogg", "flv", "f4v", "mov", "avi", "mkv", "xvid", "divx","gpx");


function fs_let_to_num($v){ //This function transforms the php.ini notation for numbers (like '2M') to an integer (2*1024*1024 in this case)
    $l = substr($v, -1);
    $ret = substr($v, 0, -1);
    switch(strtoupper($l)){
    case 'P':
        $ret *= 1024;
    case 'T':
        $ret *= 1024;
    case 'G':
        $ret *= 1024;
    case 'M':
        $ret *= 1024;
    case 'K':
        $ret *= 1024;
        break;
    }
    return $ret;
}

/*
// max file size in bytes
$ini_limit = ini_get('upload_max_filesize');

if (ini_limit == "") {
  // set the limit to 500MB, if we can't find it in INI
  $ini_limit = 500 * 1024 * 1024;
} else {
  // convert the number to bytes
  $ini_limit = fs_let_to_num($ini_limit);
}
*/

// TODO: In a future version, make this uploader honour PHP ini file size.
// for now, lets hardcode it to 10000M (essentially unlimited for a web site, who is uploading > 10GB files?)

$sizeLimit = 10000 * 1024 * 1024;

$uploader = new qqFileUploader($allowedExtensions, $sizeLimit);
$result = $uploader->handleUpload(MF_FILES_PATH);
mf_send_upload_json($result);
