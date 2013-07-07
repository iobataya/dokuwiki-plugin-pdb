<?php
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_pdb extends DokuWiki_Syntax_Plugin {
  var $ncbi;
  var $rcsb;
  var $imgCache;
  var $xmlCache;
  var $searchBox;
  var $imageW    = array();

  function syntax_plugin_pdb(){
    global $conf;
    $this->name = pdb;
    if (!class_exists('plugin_cache'))
        @require_once(DOKU_PLUGIN.$this->name.'/classes/cache.php');
    if (!class_exists('rcsb')||!class_exists('ncbi')||!class_exists('xml'))
        @require_once(DOKU_PLUGIN.$this->name.'/classes/sciencedb.php');

    $this->ncbi     = new ncbi();
    $this->rcsb     = new rcsb();
    $this->xmlCache = new plugin_cache("ncbi_esummary","structure","xml.gz");
    $this->imgCache = new plugin_cache("rcsb_image",'',"jpg");
    $this->searchBox= DOKU_PLUGIN.'pdb/pdb_search_box.htm';
    $this->imageW['small']  =  80;
    $this->imageW['medium'] = 250;
    $this->imageW['large']  = 500;
  }

  function getType(){ return 'substition'; }
  function getSort(){ return 158; }
  function connectTo($mode) { $this->Lexer->addSpecialPattern('\{\{pdb>[^}]*\}\}',$mode,'plugin_pdb'); }

 /**
  * Handle the match
  */
  function handle($match, $state, $pos, &$handler){
    $match = substr($match,6,-2);
    return array($state,explode(':',$match));
  }

 /**
  * Create output
  */
  function render($mode, &$renderer, $data){
    if($mode != 'xhtml'){return false;}
    list($state, $query) = $data;
    list($cmd,$pdbid) = $query;
    $pdbid = urlencode($pdbid);
    $cmd   = strtolower($cmd);

    if ($cmd=='small' || $cmd=='medium' || $cmd=='large'){
      $filename = $this->imgCache->GetMediaPath($pdbid);
      if ($this->rcsb->DownloadImage($pdbid,$filename)!==false)
        $renderer->doc.= $this->getImageHtml($pdbid,$cmd).NL;
      else
        $renderer->doc.= $this->getLang('pdb_no_image').NL;
      return true;

    }else if($cmd=='short'||$cmd=='long'){
      $summaryXML = $this->getSummaryXML($pdbid);
      if(!empty($summaryXML))
        $renderer->doc.= $this->getTextHtml($pdbid,$cmd,$summaryXML);
      else
        $renderer->doc.= $this->getLang('pdb_no_summary').NL;
      return true;

    }else if($cmd=='fullsmall'){
      $filename = $this->imgCache->GetMediaPath($pdbid);
      if ($this->rcsb->DownloadImage($pdbid,$filename)!==false)
        $imageHtml = $this->getImageHtml($pdbid,"small").NL;
      else
        $imageHtml = $this->getLang('pdb_no_image').NL;
      $renderer->doc.='<div class="pdb_full_left">'.$imageHtml.'</div>'.NL;


      $summaryXML = $this->getSummaryXML($pdbid);
      if(!empty($summaryXML))
        $textHtml = $this->getTextHtml($pdbid,"long",$summaryXML);
      else
        $renderer->doc.= $this->getLang('pdb_no_summary').NL;
      $renderer->doc.='<div class="pdb_full_right">'.$textHtml.'</div>'.NL;

      $renderer->doc.='<div style="clear:both"></div>'.NL;
      return true;
    }

    switch($cmd){
      case 'searchbox':
        $renderer->doc .= file_get_contents($this->searchBox);
        return true;

      case 'link':
        $renderer->doc .= $this->rcsb->ExplorerLink($pdbid);
        return true;

      case 'summaryxml':
        $summaryXML = $this->getSummaryXML($pdbid);
        $renderer->doc.="<pre>".htmlspecialchars($summaryXML)."</pre>";
        return true;

      case 'structureid':
        $summaryXML = $this->getSummaryXML($pdbid);
        $sid = $this->ncbi->GetSearchItem("Id",$summaryXML);
        if (!empty($sid)){
        $renderer->doc.="StructureID:".$sid;
        }else{
        $renderer->doc.=$pdbid." was not found.";
        }
        return true;

      case 'clear_summary':
        $renderer->doc.="Summary cleared.";
        $this->xmlCache->ClearCache();
        return true;

      case 'clear_image':
        $renderer->doc.="Image cleared.";
        $this->imgCache->ClearCache();
        return true;

      case 'remove_dir':
        $renderer->doc.="Directory removed";
        $this->xmlCache->RemoveDir();
        $this->imgCache->RemoveDir();
        return true;

      default:
        // Command was not found..
        $renderer->doc.='<div class="pdb_plugin">'.sprintf($this->getLang('plugin_cmd_not_found'),$cmd).'</div>';
        $renderer->doc.='<div class="pdb_plugin_text">'.$this->getLang('pdb_available_cmd').'</div>';
        return true;
    }
  }

 /**
  * Get renderered Html of Image
  */
  function getImageHtml($pdbid,$type){
    $pdbid = $this->rcsb->PDBformat($pdbid);
    if ($pdbid===false) return NULL;
    $url = $this->imgCache->GetMediaLink($pdbid);
    $w = $this->imageW[$type];
    $html = '<a href="'.$this->rcsb->ExplorerURL($pdbid).'"><div class="pdb_image'.$w.'">';
    $html.= '<img src="'.$url.'" alt="PDB image" title="'.$pdbid.'" width="'.$w.'"/>';
    $html.= '</div></a>';
    return $html;
  }

 /**
  * Get renderered Html of Texts
  */
  function getTextHtml($pdbid,$type,$summaryXML){
    $PdbAcc   = $this->ncbi->GetSummaryItem("PdbAcc"  ,$summaryXML);
    $PdbClass = $this->ncbi->GetSummaryItem("PdbClass",$summaryXML);
    $PdbDescr = $this->ncbi->GetSummaryItem("PdbDescr",$summaryXML);
    $LigCode  = $this->ncbi->GetSummaryItem("LigCode" ,$summaryXML);

    if (empty($PdbAcc)) return false;
    $html ='<div class="pdb_plugin"><a href="'.$this->rcsb->ExplorerURL($pdbid).'">';
    $html.='<span class="pdb_plugin_acc">'.$PdbAcc.'</span>';
    $html.='&nbsp;-&nbsp;'.$PdbClass.'</a>';
    if ($type=='long'){
        $html.='<div class="pdb_plugin_text">'.$PdbDescr.'</div>';
        if (!empty($LigCode)){
          $html.='<div class="pdb_plugin_ligand">';
          $ss = (strpos("|",$LigCode)===false)?
            $this->getLang('pdb_ligand'):$this->getlang('pdb_ligands');
          $html.=$ss.$LigCode.'</div>';
        }
    }
    $html.='</div>'.NL;
    return $html;
  }

 /**
  * Get summary XML from cache or NCBI
  */
  function getSummaryXml($pdbAcc){
    global $conf;
    $cachedXml = $this->xmlCache->GetMediaText($pdbAcc);
    if ($cachedXml!==false){ return $cachedXml; }

    // convert PDB ID to Structure ID
    $eSearchXml = $this->ncbi->SearchXml('structure',$pdbAcc);
    $ids = $this->ncbi->GetSearchItems("Id",$eSearchXml);
    $id=0;
    for ($i=0;$i<count($ids);$i++){
        $tmpXml   = $this->ncbi->SummaryXML('structure',$ids[$i]);
        $tmpPdbId = $this->ncbi->GetSummaryItem("PdbAcc",$tmpXml);
        if (strtolower($pdbAcc)==strtolower($tmpPdbId)){
            $id = $ids[$i];
        }
    }
    if ($id==0) return NULL;

    // Get summary XML
    $summary = $this->ncbi->SummaryXml('structure',$id);

    if (!empty($summary)){
      $cachePath = $this->xmlCache->GetMediaPath($pdbAcc);
      if(io_saveFile($cachePath,$summary)){
        chmod($cachePath,$conf['fmode']);
      }
    }
    return $summary;
  }
  /*
   * Convert PDB ID to Structure ID
  */
  function PDBtoStructureID($pdbAcc){

      $xml = $this->SearchXml('structure',$pdbAcc);
      $ids = $this->GetSearchItems("Id",$xml);
      for ($i=0;$i<count($ids);$i++){
          $tmpXml   = $this->SummaryXML('structure',$ids[$i]);
          $tmpPdbId = $this->GetSummaryItem("PdbAcc",$tmpXml);
          if (strtolower($pdbAcc)==strtolower($tmpPdbId)){
              return $ids[$i];
          }
      }
      return 0;
  }
}
?>
