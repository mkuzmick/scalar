<?
ini_set('display_errors',1);
error_reporting(E_ALL);

require_once('../arc/ARC2.php');

function getTextBetweenTags($string, $tagname) {
    $pattern = "/<$tagname>(.*?)<\/$tagname>/";
    preg_match($pattern, $string, $matches);
    if (!isset($matches[1])) return '';
    return $matches[1];
}
function get_meta_data($content) {  
    $content = preg_replace("'<style[^>]*>.*</style>'siU",'',$content);  // strip js 
    $content = preg_replace("'<script[^>]*>.*</script>'siU",'',$content); // strip css 
    $split = explode("\n",$content);
    $split_content = null; 
    foreach ($split as $k => $v) { 
        if (strpos(' '.$v,'<meta')) { 
             preg_match_all( "/<meta[^>]+(http\-equiv|name)=\"([^\"]*)\"[^>]" . "+content=\"([^\"]*)\"[^>]*>/i", $v, $split_content[],PREG_PATTERN_ORDER); 
        } 
    } 
    if (!$split_content) return array();
    return $split_content; 
} 

function create_excerpt($str='', $word_limit=15) {
	
	$str = trim(strip_tags($str));
	$beg_count = strlen($str);
	$str = string_limit_words($str, $word_limit);
	$str = str_replace("\n", ' ', $str);
	$str = str_replace("\r", '', $str);
	$str = str_replace("\t", ' ', $str);
	if (strlen($str) < $beg_count) $str .= ' ...';
	return $str;
	
}

// http://vision-media.ca/resources/php/php-word-limit
function string_limit_words($string, $word_limit) {
	
     $words = explode(' ', $string);
     return implode(' ', array_slice($words, 0, $word_limit));
     
}

$format = (isset($_REQUEST['format']) && !empty($_REQUEST['format'])) ? $_REQUEST['format'] : 'xml';
$url =@ trim($_REQUEST['url']);
if (empty($url)) die('');

$ns = array(		
    					'rdf'       => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
      				    'rdfs'      => 'http://www.w3.org/2000/01/rdf-schema#',
  						'foaf'      => 'http://xmlns.com/foaf/0.1/',
  						'dc'        => 'http://purl.org/dc/elements/1.1/',
  						'dcterms'   => 'http://purl.org/dc/terms/',
  						'scalar'    => 'http://vectorsjournal.org/scalar/elements/1.0/',
  						'ctag'      => 'http://commontag.org/ns#',
  						'art'       => 'http://simile.mit.edu/2003/10/ontologies/artstor#',
  						'sioc'      => 'http://rdfs.org/sioc/ns#',
  						'sioctypes' => 'http://rdfs.org/sioc/types#',
  						'foaf'      => 'http://xmlns.com/foaf/0.1/',
  						'owl'       => 'http://www.w3.org/2002/07/owl#'
					   );

$conf = array('ns' => $ns);
$resource = ARC2::getResource($conf);	
$resource->setURI($url);
$resource->setProp('rdf:type', array('value' => 'scalar:External', 'type' => 'uri'));
$resource->setProp('dcterms:title','');
$resource->setProp('dcterms:description', '');

$contents =@ file_get_contents($url);
if ($contents) {
	$title = getTextBetweenTags($contents, 'title');
	if (empty($title)) $title = '';
	$resource->setProp('dcterms:title', trim($title));
	$meta = get_meta_data($contents);
	if (!empty($meta)) {
		$desc = '';
		foreach ($meta as $row) {
			if (empty($row[1])) continue;
			if ($row[1][0]=='name'&&$row[2][0]=='description') $desc = $row[3][0];
		}
		if (empty($desc)) {
			//$desc = create_excerpt( strip_tags(getTextBetweenTags($contents, 'body')) );
		}
		$resource->setProp('dcterms:description', trim($desc));
	}
}

switch (strtolower($format)) {
	case 'xml':
		header ("content-type: text/xml");
		$conf = array('ns' => $ns, 'serializer_prettyprint_container' => true);
		$ser =@ ARC2::getRDFXMLSerializer($conf);	
		$doc =@ $ser->getSerializedIndex( $resource->index );	
		break;
	case 'json';
		$parser =@ ARC2::getRDFParser();
		$doc =@ $parser->toRDFJSON($resource->index);
		break;							
}

echo $doc;  

?>