<?php
class eiseAPIClient {

public static $defaultConf = array(
    'sleepMicrosecs' => 1000
    // , 'verbose' => 'htmlspecialchars'
    , 'passSymbolsToShow' => 4
    , 'host' => 'api.host'
    , 'api_key' => null
    , 'login' => null
    , 'password' => null
    , 'baseurl' => ''
    , 'http' => False
    );  


public function __construct($conf){
    
    $this->conf = array_merge(self::$defaultConf, $conf);

    $this->authenticate();

}


public function authenticate(){

}

public function queryAPI($query, $url = '/', $method = 'POST', $format = 'application/x-www-form-urlencoded'){

    $url = $this->getFullURL($url);

    if ($method==='GET' && $query) {
        if(is_array($query)){
            $query = http_build_query($query);
        }
        $url .= (!preg_match('/^\?/', $query) ? '?' : '').$query;
    }

    $this->v("URL: {$url}");

    $ch = curl_init($url);

    $arrCURLOptions = $this->prepareCURLOptions( $query );

    if(!in_array($method, ['GET', 'HEAD'])) {

        $arrCURLOptions[CURLOPT_POST] =  true; 

        switch($format){
            case 'application/json':
                
                $arrCURLOptions[CURLOPT_POSTFIELDS] = json_encode($query) ;
                $arrCURLOptions[CURLOPT_HTTPHEADER] = array_merge($arrCURLOptions[CURLOPT_HTTPHEADER], array('Accept: application/json'));
                $arrCURLOptions[CURLOPT_HTTPHEADER] = array_merge($arrCURLOptions[CURLOPT_HTTPHEADER], array('Content-Type: application/json'));
                $arrCURLOptions[CURLOPT_HTTPHEADER] = array_merge($arrCURLOptions[CURLOPT_HTTPHEADER], array('Content-Length: '.strlen(json_encode($query))));

            default:
            
                $q = http_build_query($query);
                $arrCURLOptions[CURLOPT_POSTFIELDS] =  $q;
                $arrCURLOptions[CURLOPT_HTTPHEADER] = array_merge($arrCURLOptions[CURLOPT_HTTPHEADER], array('Content-Type: application/x-www-form-urlencoded'));
                $arrCURLOptions[CURLOPT_HTTPHEADER] = array_merge($arrCURLOptions[CURLOPT_HTTPHEADER], array('Content-Length: '.strlen($q)));
        }

        if($method!=='POST'){
            $arrCURLOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }
        

    }

    $this->v("CURL Options: ".var_export($arrCURLOptions, true));

    @curl_setopt_array($ch, $arrCURLOptions);

    $error = curl_error($ch);
    if ($error){

        $ci = curl_getinfo($ch);
        
        throw new eiseAPIClientException("Curl error: ".$error, $ci['http_code'], NULL, $ci);

    }

    if($this->conf['verbose']){
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
    }
    

    $iAttempt = 0;
    $this->nAttempts = 3;
    while(!($result = curl_exec($ch)) && $iAttempt < $this->nAttempts) {
        $iAttempt++;
        $this->v( "Attempt {$iAttempt} of {$this->nAttempts}...\r\n" );
        usleep(2000000);
    }

    $ci = curl_getinfo($ch);

    curl_close($ch);

    if($this->conf['verbose']){
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        $this->v("Verbose information:\n", htmlspecialchars($verboseLog), "\n" );
    }

    $res = $this->processResult($result, $ci);

    return $res;
    
}

function getFullURL($url){

    $s = (!preg_match('/^local/', $this->conf['host']) && !$this->conf['http'] ? 's' : '');

    return (preg_match('/^http/', $url) ? $url : "http{$s}://{$this->conf['host']}{$this->conf['baseurl']}{$url}");
}

public function postFile($filepath, $mimetype, $filename, $url, $postFields=[], $conf=[]){

    $defaultConf = ['file_field_name'=>'file'];

    $conf = array_merge($defaultConf, $conf);

    $url = $this->getFullURL($url);

    $this->v("URL: {$url}");

    $ch = curl_init($url);

    $arrCURLOptions = $this->prepareCURLOptions( $query );

    $arrCURLOptions[CURLOPT_VERBOSE] = 1;
    // curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);

    $arrCURLOptions[CURLOPT_POST] = true;
    $postFields[$conf["file_field_name"]] = new CURLFile($filepath, $mimetype, $filename);

    $arrCURLOptions[CURLOPT_POSTFIELDS] = $postFields;

    @curl_setopt_array($ch, $arrCURLOptions);

    $result = curl_exec($ch);

    return $result;

}

public function prepareCURLOptions($query){

    $arrToSet = array(
            CURLOPT_RETURNTRANSFER => true
            , CURLOPT_TIMEOUT => 10
            , CURLOPT_SSL_VERIFYPEER => false
            , CURLOPT_HTTPHEADER => array()
        );

    return $arrToSet;
}


/**
 * processResult() method tries to parse result as JSON, then XML. If it succeeds it returns it as array or object, otherwise it returns CURL result as is.
 * 
 * @param string $result Query result as it's been obtained from CURL
 * @param array $curlinfo Associative array obtained from curl_getinfo() function
 *
 * @return mixed Array if there's JSON, simpleXML object if there's XML, string if it's been not recognized as any previous formats
 */
public function processResult($result, $curlinfo = null){

    $this->v('CURL info: '.var_export($curlinfo, true));

    if($curlinfo['http_code']!=200){
        throw new eiseAPIClientException('Error processing result (len '.strlen($result).' bytes): '.$result, $curlinfo['http_code'], NULL, $curlinfo);
    }

    $this->v('Response text ('.strlen($result).' byte(s)): '.mb_substr($result, 0, 255)."\r\n\r\n");

    /**
     * try to parse it as JSON and return array in case of success
     */
    $aJSON = @json_decode($result, true);
    if(is_array($aJSON))
        return $aJSON;

    /**
     * Try to parse it as XML and return object if succeded
     */
    try {
        $oXML = @new SimpleXMLElement($result);
    }catch(Exception $e){}

    if(is_object($oXML))
        return $oXML;

    /**
     * Else return text
     */
    return $result;

}

/**
 * This function echoes if 'verbose' configuration flag is on
 *
 * @param string $string - string to echo.
 *
 * @return void
 */
protected function v($string){
    if($this->conf['verbose']){
        echo ($this->conf['verbose']==='htmlspecialchars' ? '<br>'.htmlspecialchars(Date('Y-m-d H:i:s').': '.$string) : "\r\n".Date('Y-m-d H:i:s').': '.trim($string));
        ob_flush();
        flush();
    }
}

public function coverPassword(){

    $nSymsToShow = min(strlen($this->conf['password']), $this->conf['passSymbolsToShow']);
    $this->conf['passCovered'] = substr($this->conf['password'], 0, $nSymsToShow).str_repeat("*",strlen($this->conf['password'])-$nSymsToShow);
    
}

}

class eiseAPIClientException extends Exception {

public function __construct($msg = '', $code = 0, $previous = NULL, $curlinfo=null){
    $this->curlinfo = $curlinfo;
    parent::__construct($msg, $code, $previous);
}

}