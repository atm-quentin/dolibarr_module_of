<?php

require('config.php');

dol_include_once('/of/class/ordre_fabrication_asset.class.php');
dol_include_once('/of/lib/of.lib.php');
dol_include_once('/core/lib/ajax.lib.php');
dol_include_once('/core/lib/product.lib.php');
dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/product/class/product.class.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/fourn/class/fournisseur.commande.class.php');
dol_include_once('/product/class/html.formproduct.class.php');
dol_include_once('/core/lib/date.lib.php');
dol_include_once('/core/lib/pdf.lib.php');
dol_include_once('/nomenclature/class/nomenclature.class.php');

dol_include_once('/asset/class/asset.class.php'); // TODO à remove avec les déclaration d'objet TAsset_type

if(!$user->rights->of->of->lire) accessforbidden();

// Load traductions files requiredby by page
$langs->load("other");
$langs->load("orders");
$langs->load("of@of");

$hookmanager->initHooks(array('ofcard'));

// Get parameters
_action();

// Protection if external user
if ($user->societe_id > 0)
{
	//accessforbidden();
}

function _action() {
	global $user, $db, $conf, $langs;
	$PDOdb=new TPDOdb;
	//$PDOdb->debug=true;

	/*******************************************************************
	* ACTIONS
	*
	* Put here all code to do according to value of "action" parameter
	********************************************************************/

	$action=__get('action','view');
	switch($action) {
		case 'new':
		case 'add':
			$assetOf=new TAssetOF;
			$assetOf->set_values($_REQUEST);

			$fk_product = __get('fk_product',0,'int');
			$fk_nomenclature = __get('fk_nomenclature',0,'int');

			_fiche($PDOdb, $assetOf,'edit', $fk_product, $fk_nomenclature);

			break;

		case 'edit':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id']);

			_fiche($PDOdb,$assetOf,'edit');
			break;

		case 'create':
		case 'save':
			$assetOf=new TAssetOF;
			if(!empty($_REQUEST['id'])) {
				$assetOf->load($PDOdb, $_REQUEST['id'], false);
				$mode = 'view';
			}
			else {

				$mode = $action == 'create' ? 'view' : 'edit';
			}

			$assetOf->set_values($_REQUEST);

			$fk_product = __get('fk_product_to_add',0);
			$quantity_to_create = __get('quantity_to_create',1);
			$fk_nomenclature = __get('fk_nomenclature',0);
			if($fk_product > 0) {
				$assetOf->addLine($PDOdb, $fk_product, 'TO_MAKE',$quantity_to_create,0,'',$fk_nomenclature);
			}

			if(!empty($_REQUEST['TAssetOFLine']))
			{
				foreach($_REQUEST['TAssetOFLine'] as $k=>$row)
				{
				    if(!isset( $assetOf->TAssetOFLine[$k] ))  $assetOf->TAssetOFLine[$k] = new TAssetOFLine;

					if (!empty($conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED))
					{
						$assetOf->TAssetOFLine[$k]->set_workstations($PDOdb, $row['fk_workstation']);
						unset($row['fk_workstation']);
					}

					$assetOf->TAssetOFLine[$k]->set_values($row);
				}

				foreach($assetOf->TAssetOFLine as &$line) {
					$line->TAssetOFLine=array();
				}
			}

			if(!empty($_REQUEST['TAssetWorkstationOF'])) {
				foreach($_REQUEST['TAssetWorkstationOF'] as $k=>$row)
				{
					//Association des utilisateurs à un poste de travail
					if (!empty($conf->global->ASSET_DEFINED_USER_BY_WORKSTATION))
					{
						$assetOf->TAssetWorkstationOF[$k]->set_users($PDOdb, $row['fk_user']);
						unset($row['fk_user']);
					}

					//Association des opérations à une poste de travail (mode opératoire)
					if (!empty($conf->global->ASSET_DEFINED_OPERATION_BY_WORKSTATION))
					{
						$assetOf->TAssetWorkstationOF[$k]->set_tasks($PDOdb, $row['fk_task']);
						unset($row['fk_task']);
					}

					$assetOf->TAssetWorkstationOF[$k]->set_values($row);
				}
			}

			$assetOf->entity = $conf->entity;

			//Permet de mettre à jour le lot de l'OF parent
			if (!empty($assetOf->fk_assetOf_parent)) $assetOf->update_parent = true;
			$assetOf->save($PDOdb);

			_fiche($PDOdb,$assetOf, $mode);

			break;

		case 'valider':
			$error = 0;
			$assetOf=new TAssetOF;
            $id = GETPOST('id');
            if(empty($id)) exit('Where is Waldo ?');

			$assetOf->load($PDOdb, $id);

           //Si use_lot alors check de la saisie du lot pour chaque ligne avant validation
			if (!empty($conf->global->USE_LOT_IN_OF) && !empty($conf->global->OF_LOT_MANDATORY)) {
				if (!$assetOf->checkLotIsFill())
				{
					_fiche($PDOdb,$assetOf, 'view');
					break;
				}
			}

			$res = $assetOf->validate($PDOdb);

			if ($res > 0)
			{
				//Relaod de l'objet OF parce que createOfAndCommandesFourn() fait tellement de truc que c'est le bordel

				$assetOf=new TAssetOF;
				if(!empty($_REQUEST['id'])) $assetOf->load($PDOdb, $_REQUEST['id'], false);
			}

			_fiche($PDOdb, $assetOf, 'view');

			break;

		case 'lancer':
			$assetOf=new TAssetOF;
            $id = GETPOST('id');
            if(empty($id)) exit('Where is Waldo ?');

			$assetOf->load($PDOdb,$id);

			$assetOf->openOF($PDOdb);

			$assetOf->load($PDOdb,$id);
			_fiche($PDOdb, $assetOf, 'view');

			break;

		case 'terminer':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id']);
			$assetOf->closeOF($PDOdb);
			$assetOf->load($PDOdb, $_REQUEST['id']);

			_fiche($PDOdb,$assetOf, 'view');

			break;

		case 'delete':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id']);

			//$PDOdb->db->debug=true;
			$assetOf->delete($PDOdb);


			header('Location: '.dol_buildpath('/of/liste_of.php?delete_ok=1',1));
			exit;

			break;

		case 'createDocOF':

			$id_of = $_REQUEST['id'];

			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $id_of, false);

			$TOFToGenerate = array($assetOf->rowid);

			if($conf->global->ASSET_CONCAT_PDF) $assetOf->getListeOFEnfants($PDOdb, $TOFToGenerate, $assetOf->rowid);
//			var_dump($TOFToGenerate);exit;
			foreach($TOFToGenerate as $id_of) {

				$assetOf=new TAssetOF;
				$assetOf->load($PDOdb, $id_of, false);
				//echo $id_of;
				$TRes[] = generateODTOF($PDOdb, $assetOf);
				//echo '...ok<br />';
			}

			$TFilePath = get_tab_file_path($TRes);
		//	var_dump($TFilePath);exit;
			if($conf->global->ASSET_CONCAT_PDF) {
				ob_start();
				$pdf=pdf_getInstance();
				if (class_exists('TCPDF'))
				{
					$pdf->setPrintHeader(false);
					$pdf->setPrintFooter(false);
				}
				$pdf->SetFont(pdf_getPDFFont($langs));

				if ($conf->global->MAIN_DISABLE_PDF_COMPRESSION) $pdf->SetCompression(false);
				//$pdf->SetCompression(false);

				$pagecount = concatPDFOF($pdf, $TFilePath);

				if ($pagecount)
				{
					$pdf->Output($TFilePath[0],'F');
					if (! empty($conf->global->MAIN_UMASK))
					{
						@chmod($file, octdec($conf->global->MAIN_UMASK));
					}
				}
				ob_clean();
			}

			header("Location: ".DOL_URL_ROOT."/document.php?modulepart=of&entity=1&file=".$TRes[0]['dir_name']."/".$TRes[0]['num_of'].".pdf");

			break;

		case 'control':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id']);

			$subAction = __get('subAction', false);
			if ($subAction) $assetOf->updateControl($PDOdb, $subAction);

			_fiche_control($PDOdb, $assetOf);

			break;

		case 'addAssetLink':
			$assetOf=new TAssetOF;
            $assetOf->load($PDOdb, __get('id', 0, 'int'));

			$idLine = __get('idLine', 0, 'int');
			$idAsset = __get('idAsset', 0, 'int');

			if ($idLine && $idAsset)
			{
				$find = false;
				foreach ($assetOf->TAssetOFLine as $TAssetOFLine)
				{
					if ($TAssetOFLine->getId() == $idLine)
					{
						$find = true;

						$asset = new TAsset;
						$asset->load($PDOdb, $idAsset);
						$TAssetOFLine->addAssetLink($asset);
						break;
					}
				}

				if (!$find) setEventMessage($langs->trans('error_of_on_id_asset'), 'errors');
			}
			else
			{
				setEventMessage($langs->trans('error_of_wrong_id_asset'), 'errors');
			}

           _fiche($PDOdb, $assetOf, 'edit');

			break;

        case 'deleteAssetLink':
            $assetOf=new TAssetOF;
            $assetOf->load($PDOdb, __get('id', 0, 'int'));

			$idLine = __get('idLine', 0, 'int');
			$idAsset = __get('idAsset', 0, 'int');

			if ($idLine && $idAsset)
			{
				TAsset::del_element_element($PDOdb, $idLine, $idAsset, 'TAsset');
			}
			else
			{
				setEventMessage($langs->trans('error_of_no_ids'), 'errors');
			}

           _fiche($PDOdb, $assetOf, 'edit');

           break;

		default:

			$assetOf=new TAssetOF;
			if(GETPOST('id')>0) $assetOf->load($PDOdb, GETPOST('id'), false);
			else if(GETPOST('ref')!='') $assetOf->loadBy($PDOdb, GETPOST('ref'), 'numero', false);

			_fiche($PDOdb, $assetOf, 'view');

			break;
	}

}



function generateODTOF(&$PDOdb, &$assetOf) {

	global $db,$conf, $TProductCachegenerateODTOF,$langs;

	$TBS=new TTemplateTBS();
	dol_include_once("/product/class/product.class.php");

	$TToMake = array(); // Tableau envoyé à la fonction render contenant les informations concernant les produit à fabriquer
	$TNeeded = array(); // Tableau envoyé à la fonction render contenant les informations concernant les produit nécessaires
	$TWorkstations = array(); // Tableau envoyé à la fonction render contenant les informations concernant les stations de travail
	$TWorkstationUser = array(); // Tableau de liaison entre les postes et les utilisateurs
	$TWorkstationTask = array(); // Tableau de liaison entre les postes et les tâches 'mode opératoire'
	$TAssetWorkstation = array(); // Tableau de liaison entre les composants et les postes de travails
	$TControl = array(); // Tableau de liaison entre l'OF et les controles associés

	$societe = new Societe($db);
	$societe->fetch($assetOf->fk_soc);

	//pre($societe,true); exit;

	if (!empty($conf->quality->enabled))
	{
		$TControl = $assetOf->getControlPDF($PDOdb);
	}

	if(empty($TProductCachegenerateODTOF))$TProductCachegenerateODTOF=array();

	// On charge les tableaux de produits à fabriquer, et celui des produits nécessaires
	foreach($assetOf->TAssetOFLine as $k=>&$v) {

		if(!isset($TProductCachegenerateODTOF[$v->fk_product])) {

			$prod_cache = new Product($db);
			if($prod_cache->fetch($v->fk_product)>0) {
				$prod_cache->fetch_optionals($prod_cache->id);
				$TProductCachegenerateODTOF[$v->fk_product]=$prod_cache;
			}
		}
		else{
			//echo 'cache '.$v->fk_product.':'.$TProductCachegenerateODTOF[$v->fk_product]->ref.' / '.$TProductCachegenerateODTOF[$v->fk_product]->id.'<br />';
		}

		$prod = &$TProductCachegenerateODTOF[$v->fk_product];

		if($conf->nomenclature->enabled){

				$n = new TNomenclature;

				if(!empty($v->fk_nomenclature)) {
					$n->load($PDOdb, $v->fk_nomenclature);
					$TTypesProductsNomenclature = $n->getArrayTypesProducts();
				}

		}

		$qty = !empty($v->qty_needed) ? $v->qty_needed : $v->qty;

		if(!empty($conf->asset->enabled)) {
			$TAssetType = new TAsset_type;
			$TAssetType->load($PDOdb, $prod->array_options['options_type_asset']);
			$unitLabel = ($TAssetType->measuring_units == 'unit' || $TAssetType->gestion_stock == 'UNIT') ? $langs->transnoentities('unit_s_') : measuring_units_string($prod->weight_units,'weight');

		}
		else{
			$unitLabel = $langs->transnoentities('unit_s_');
		}

		if($v->type == "TO_MAKE") {
			$TToMake[] = array(
				'type' => $v->type
				, 'qte' => $qty.' '.utf8_decode($unitLabel)
				, 'nomProd' => $prod->ref
				, 'designation' => utf8_decode(dol_string_nohtmltag($prod->label))
				, 'dateBesoin' => date("d/m/Y", $assetOf->date_besoin)
				, 'lot_number' => $v->lot_number ? "\n(Lot numero ".$v->lot_number.")" : ""
				, 'code_suivi_ponderal' => $prod->array_options['options_suivi_ponderal'] ? "\n".$prod->array_options['options_suivi_ponderal'] : "\n(Aucun)"
			);

		}
		else if($v->type == "NEEDED") {
			$TNeeded[] = array(
				'type' => $conf->nomenclature->enabled ? $TTypesProductsNomenclature[$v->fk_product] : $v->type
				, 'qte' => $qty
				, 'nomProd' => $prod->ref
				, 'designation' => utf8_decode($prod->label)
				, 'dateBesoin' => date("d/m/Y", $assetOf->date_besoin)
				, 'poids' => ($prod->weight) ? $prod->weight : 1
				, 'unitPoids' => utf8_decode($unitLabel)
				, 'finished' => $prod->finished?"PM":"MP"
				, 'lot_number' => $v->lot_number ? "\n(Lot numero ".$v->lot_number.")" : ""
				, 'code_suivi_ponderal' => $prod->array_options['options_suivi_ponderal'] ? "\n(Code suivi ponderal : ".$prod->array_options['options_suivi_ponderal'].")" : ""
				, 'note_private' => utf8_decode($v->note_private)
			);

			if (!empty($conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED))
			{
				$TAssetWorkstation[] = array(
					'nomProd'=>utf8_decode($prod->label)
					,'workstations'=>utf8_decode($v->getWorkstationsPDF($db))
				);
			}

		}

	}
//exit;
	// On charge le tableau d'infos sur les stations de travail de l'OF courant
	foreach($assetOf->TAssetWorkstationOF as $k => $v) {
		$TWorkstations[] = array(
			'libelle' => utf8_decode($v->ws->libelle)
			//,'nb_hour_max' => utf8_decode($v->ws->nb_hour_max)
			,'nb_hour_max' => utf8_decode($v->ws->nb_hour_capacity)
			,'nb_hour_real' => utf8_decode($v->nb_hour_real)
			,'nb_hour_preparation' => utf8_decode($v->nb_hour_prepare)
			,'nb_heures_prevues' => utf8_decode($v->nb_hour)
			,'note_private' => utf8_decode($v->note_private)
		);

		if (!empty($conf->global->ASSET_DEFINED_USER_BY_WORKSTATION))
		{
			$TWorkstationUser[] = array(
				'workstation'=>utf8_decode($v->ws->libelle)
				,'users'=>utf8_decode($v->getUsersPDF($PDOdb))
			);
		}

		if (!empty($conf->global->ASSET_DEFINED_OPERATION_BY_WORKSTATION))
		{
			$TWorkstationTask[] = array(
				'workstation'=>utf8_decode($v->ws->libelle)
				,'tasks'=>utf8_decode($v->getTasksPDF($PDOdb))
			);
		}

	}

	$dirName = 'OF'.$assetOf->rowid.'('.date("d_m_Y").')';
	$dir = DOL_DATA_ROOT.( $conf->entity>1 ? '/'.$conf->entity : ''  ).'/of/'.$dirName.'/';

	@mkdir($dir, 0777, true);

	if(defined('TEMPLATE_OF')){
		$template = TEMPLATE_OF;
	}
	else{
		$template = "templateOF.odt";
		//$template = "templateOF.doc";
	}

	$refcmd = '';
	if(!empty($assetOf->fk_commande)) {
		$cmd = new Commande($db);
		$cmd->fetch($assetOf->fk_commande);
		$refcmd = $cmd->ref;
	}

	$barcode_pic = getBarCodePicture($assetOf);
//var_dump($TToMake);
	$file_path = $TBS->render(dol_buildpath('/of/exempleTemplate/'.$template)
		,array(
			'lignesToMake'=>$TToMake
			,'lignesNeeded'=>$TNeeded
			,'lignesWorkstation'=>$TWorkstations
			,'lignesAssetWorkstations'=>$TAssetWorkstation
			,'lignesUser'=>$TWorkstationUser
			,'lignesTask'=>$TWorkstationTask
			,'lignesControl'=>$TControl
		)
		,array(
			'date'=>date("d/m/Y")
			,'numeroOF'=>$assetOf->numero
			,'statutOF'=>utf8_decode(TAssetOF::$TStatus[$assetOf->status])
			,'prioriteOF'=>utf8_decode(TAssetOF::$TOrdre[$assetOf->ordre])
			,'date_lancement'=>date("d/m/Y", $assetOf->date_lancement)
			,'date_besoin'=>date("d/m/Y", $assetOf->date_besoin)
			,'refcmd'=>$refcmd
			,'societe'=>$societe->name
			,'logo'=>DOL_DATA_ROOT."/mycompany/logos/".MAIN_INFO_SOCIETE_LOGO
			,'barcode'=>$barcode_pic
			,'use_lot'=>(int) $conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED
			,'defined_user'=>(int) $conf->global->ASSET_DEFINED_USER_BY_WORKSTATION
			,'defined_task'=>(int) $conf->global->ASSET_DEFINED_OPERATION_BY_WORKSTATION
			,'use_control'=>(int) $conf->global->ASSET_USE_CONTROL
			,'note_of'=>$assetOf->note
		)
		,array()
		,array(
			'outFile'=>$dir.$assetOf->numero.".odt"
			,"convertToPDF"=>true
			//'outFile'=>$dir.$assetOf->numero.".doc"
		)

	);

	return array('file_path'=>$file_path, 'dir_name'=>$dirName, 'num_of'=>$assetOf->numero);

	header("Location: ".DOL_URL_ROOT."/document.php?modulepart=of&entity=1&file=".$dirName."/".$assetOf->numero.".pdf");
	//header("Location: ".DOL_URL_ROOT."/document.php?modulepart=asset&entity=1&file=".$dirName."/".$assetOf->numero.".doc");

}

function drawCross($im, $color, $x, $y){

	imageline($im, $x - 10, $y, $x + 10, $y, $color);
    imageline($im, $x, $y- 10, $x, $y + 10, $color);

}

function getBarCodePicture(&$assetOf) {

	dol_include_once('/of/php_barcode/php-barcode.php');

	$code = $assetOf->numero;

  	$fontSize = 10;   // GD1 in px ; GD2 in point
  	$marge    = 10;   // between barcode and hri in pixel
  	$x        = 145;  // barcode center
  	$y        = 50;  // barcode center
  	$height   = 50;   // barcode height in 1D ; module size in 2D
  	$width    = 2;    // barcode height in 1D ; not use in 2D
  	$angle    = 0;   // rotation in degrees : nb : non horizontable barcode might not be usable because of pixelisation

  	$type     = 'code128';

 	$im     = imagecreatetruecolor(300, 100);
  	$black  = ImageColorAllocate($im,0x00,0x00,0x00);
  	$white  = ImageColorAllocate($im,0xff,0xff,0xff);
  	$red    = ImageColorAllocate($im,0xff,0x00,0x00);
  	$blue   = ImageColorAllocate($im,0x00,0x00,0xff);
  	imagefilledrectangle($im, 0, 0, 300, 300, $white);

  	$data = Barcode::gd($im, $black, $x, $y, $angle, $type, array('code'=>$code), $width, $height);
  	if ( isset($font) ){
    	$box = imagettfbbox($fontSize, 0, $font, $data['hri']);
		$len = $box[2] - $box[0];
		Barcode::rotate(-$len / 2, ($data['height'] / 2) + $fontSize + $marge, $angle, $xt, $yt);
		imagettftext($im, $fontSize, $angle, $x + $xt, $y + $yt, $blue, $font, $data['hri']);
	}

	$tmpfname = tempnam(sys_get_temp_dir(), 'barcode_pic');
	imagepng($im, $tmpfname);
	imagedestroy($im);

	return $tmpfname;

}

function get_tab_file_path($TRes) {

	$tab = array();

	foreach($TRes as $TData) {
		$tab[] = strtr($TData['file_path'], array('.odt'=>'.pdf'));
	}

	return $tab;

}

function _fiche_ligne(&$form, &$of, $type){
	global $db, $conf, $langs,$hookmanager,$user;
//TODO rules guys ! To Facto ! AA
	$formProduct = new FormProduct($db);

    $PDOdb=new TPDOdb;
	$TRes = array();
	foreach($of->TAssetOFLine as $k=>&$TAssetOFLine){
		$product = &$TAssetOFLine->product;
        if(is_null($product)) {
            $product=new Product($db);
            $product->fetch($TAssetOFLine->fk_product);
			$product->fetch_optionals();
        }


		$conditionnement = $TAssetOFLine->conditionnement;

		if(!empty($conf->asset->enabled)) {
			$TAssetType = new TAsset_type;
			$TAssetType->load($PDOdb, $product->array_options['options_type_asset']);
			$conditionnement_unit = ($TAssetType->measuring_units == 'unit' || $TAssetType->gestion_stock == 'UNIT') ? 'unité(s)' : $TAssetOFLine->libUnite();
		}
		else{
			$conditionnement_unit = 'unité(s)'; // TODO translate
		}
		//$conditionnement_unit = $TAssetOFLine->libUnite();

		if($TAssetOFLine->measuring_units!='unit' && !empty($TAssetOFLine->measuring_units)) {
            $conditionnement_label = ' / '.$conditionnement." ".$conditionnement_unit;
            $conditionnement_label_edit = ' par '.$form->texte('', 'TAssetOFLine['.$k.'][conditionnement]', $conditionnement, 5,5,'','').$conditionnement_unit;

		}
        else{
            $conditionnement_label=$conditionnement_label_edit='';
        }

        if($TAssetOFLine->type == 'NEEDED' && $type == 'NEEDED'){
			$stock_needed = TAssetOF::getProductStock($product->id);

			$product->load_stock();
			list($total_qty_tomake, $total_qty_needed) = _calcQtyOfProductInOf($db, $conf, $product);
			$stock_theo = $product->stock_theorique + $total_qty_tomake - $total_qty_needed;

			$label = $product->getNomUrl(1).' '.$product->label;
			$label.= ' - '.$langs->trans("Stock") . ' : ' . ($stock_needed>0 ? $stock_needed : '<span style="color:red;font-weight:bold;">'.$stock_needed.'</span>');
			$label.= ' - '.$langs->trans("StockTheo") . ' : ' . ($stock_theo>0 ? $stock_theo : '<span style="color:red;font-weight:bold;">'.$stock_theo.'</span>');
			$label.= _fiche_ligne_asset($PDOdb,$form, $of, $TAssetOFLine, 'NEEDED');

			$TLine = array(
					'id'=>$TAssetOFLine->getId()
					,'idprod'=>$form->hidden('TAssetOFLine['.$k.'][fk_product]', $product->id)
					,'lot_number'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][lot_number]', $TAssetOFLine->lot_number, 15,50,'type_product="NEEDED" fk_product="'.$product->id.'" rel="lot-'.$TAssetOFLine->getId().'" ','TAssetOFLineLot') : $TAssetOFLine->lot_number
					,'libelle'=>$label
					,'qty_needed'=>$TAssetOFLine->qty_needed .' x '.price(price2num($TAssetOFLine->compo_estimated_cost,'MT'),0,'',1,-1,-1,$conf->currency).$conditionnement_label
					,'qty'=>(($of->status=='DRAFT' && $form->type_aff== "edit") ? $form->texte('', 'TAssetOFLine['.$k.'][qty]', $TAssetOFLine->qty, 5,50) : $TAssetOFLine->qty .(empty($user->rights->of->of->price) ? '' : ' x '.price(price2num($TAssetOFLine->compo_planned_cost,'MT'),0,'',1,-1,-1,$conf->currency)))
					,'qty_used'=>((($of->status=='OPEN' || $of->status == 'CLOSE') && $form->type_aff) ? $form->texte('', 'TAssetOFLine['.$k.'][qty_used]', $TAssetOFLine->qty_used, 5,50) : $TAssetOFLine->qty_used.(empty($user->rights->of->of->price) ? '' : ' x '.price(price2num($TAssetOFLine->compo_cost,'MT'),0,'',1,-1,-1,$conf->currency)))
					,'qty_toadd'=> $TAssetOFLine->qty - $TAssetOFLine->qty_used
					,'workstations'=> $conf->workstation->enabled ? $TAssetOFLine->visu_checkbox_workstation($db, $of, $form, 'TAssetOFLine['.$k.'][fk_workstation][]') : ''
					,'delete'=> ($form->type_aff=='edit' && ($of->status=='DRAFT' || (!empty($conf->global->OF_USE_DESTOCKAGE_PARTIEL) && $of->status!='CLOSE' && empty($TAssetOFLine->qty_used))) ) ? '<a href="javascript:deleteLine('.$TAssetOFLine->getId().',\'NEEDED\');">'.img_picto('Supprimer', 'delete.png').'</a>' : ''
					,'fk_entrepot' => !empty($conf->global->ASSET_MANUAL_WAREHOUSE) && ($of->status == 'DRAFT' || $of->status == 'VALID') && $form->type_aff == 'edit' ? $formProduct->selectWarehouses($TAssetOFLine->fk_entrepot, 'TAssetOFLine['.$k.'][fk_entrepot]', '', 0, 0, $TAssetOFLine->fk_product) : $TAssetOFLine->getLibelleEntrepot($PDOdb)
                	,'note_private'=>(($of->status=='DRAFT') ? $form->zonetexte('', 'TAssetOFLine['.$k.'][note_private]', $TAssetOFLine->note_private, 50,1) : $TAssetOFLine->note_private)
			);

			$action = $form->type_aff;
			$parameter=array('of'=>&$of, 'line'=>&$TLine,'type'=>'NEEDED');
			$res = $hookmanager->executeHooks('lineObjectOptions', $parameter, $TAssetOFLine, $action);

			if($res>0 && !empty($hookmanager->resArray)) {

				$TLine = $hookmanager->resArray;

			}

			$TRes[] = $TLine;
		}
		elseif($TAssetOFLine->type == "TO_MAKE" && $type == "TO_MAKE"){
			if(empty($TAssetOFLine->TFournisseurPrice)) {

				$TAssetOFLine->loadFournisseurPrice($PDOdb);
			}



			// Permet de sélectionner par défaut "(Fournisseur "Interne" => Fabrication interne)" si le produit TO_MAKE n'a pas de stock lorsqu'on est en mode edit et que la ligne TO_MAKE n'a pas encore de prix fournisseur enregistré
			dol_include_once('/product/class/product.class.php');
			$p = new Product($db);
			$selected = 0;

			if($p->fetch($TAssetOFLine->fk_product)) {
				$p->load_stock();
				$p->stock_reel;
				if($TAssetOFLine->type === 'TO_MAKE' && $p->stock_reel <= 0 && $_REQUEST['action'] === 'edit') $selected = -2;
			}
			// *************************************************************




			$Tab=array();
			foreach($TAssetOFLine->TFournisseurPrice as &$objPrice) {

				$label = "";

				//Si on a un prix fournisseur pour le produit
				if($objPrice->price > 0)
				{
					$unit = $objPrice->quantity == 1 ? $langs->trans('OFUnit') : $langs->trans('OFUnits');
					$label .= floatval($objPrice->price).' '.$conf->currency.' - '.$objPrice->quantity.' '.$unit.' -';
				}

				//Affiche le nom du fournisseur
				$label .= ' ('.$langs->trans('OFSupplierLineName', utf8_encode ($objPrice->name));

				//Prix unitaire minimum si renseigné dans le PF
				if($objPrice->quantity > 0){
					$label .= ' '.$langs->trans('OFSupplierLineMinQty', $objPrice->quantity);
				}

				//Affiche le type du PF :
				if($objPrice->compose_fourni){//			soit on fabrique les composants
					$label .= ' =>'.$langs->trans('OFSupplierLineComp');
				}
				elseif($objPrice->quantity <= 0){//			soit on a le produit finis déjà en stock
					$label .= ' =>'.$langs->trans('OFSupplierLineStockRemoval');
				}

				if($objPrice->quantity > 0){//				soit on commande a un fournisseur
					$label .= ' =>'.$langs->trans('OFSupplierLineSupOrder');
				}

				$label .= ")";

				$Tab[ $objPrice->rowid ] = array(
												'label' => $label,
												'compose_fourni' => ($objPrice->compose_fourni) ? $objPrice->compose_fourni : 0
											);

			}

			if ($conf->nomenclature->enabled) {
				dol_include_once('/nomenclature/class/nomenclature.class.php');

				if ($of->status == 'DRAFT' && !$TAssetOFLine->nomenclature_valide) {
					$TNomenclature = TNomenclature::get($PDOdb, $TAssetOFLine->fk_product, true);

					if(count($TNomenclature) > 0 ) {
						$nomenclature = '<div>'.$form->combo('', 'TAssetOFLine['.$k.'][fk_nomenclature]', $TNomenclature, $TAssetOFLine->fk_nomenclature);

						if ($form->type_aff=='edit') {
							$nomenclature .= '<a href="#" class="valider_nomenclature" data-id_of="' . $of->getId() . '" data-product="' . $TAssetOFLine->fk_product . '" data-of_line="' . $TAssetOFLine->rowid . '">'.$langs->trans('OFValidate').'</a>';
						}
						else {
							$nomenclature .= " - ".$langs->trans('NomenclatureToSelect');
						}

						$nomenclature.='</div>';
					}
					else{
						$nomenclature='';
					}

				} else {
					$n=new TNomenclature;
					$n->load($PDOdb, $TAssetOFLine->fk_nomenclature);
					$nomenclature = '<div>' .(String) $n;
					$picture = ($TAssetOFLine->nomenclature_valide ? 'ok.png' : 'no.png');
					$nomenclature .= ' <img src="img/' . $picture . '" style="padding-left: 2px; vertical-align: middle;" /></div>';
				}


			}

			//($of->status=='DRAFT') ? $form->combo('', 'TAssetOFLine['.$k.'][fk_nomenclature]', _getArrayNomenclature($PDOdb, $TAssetOFLine), $TAssetOFLine->fk_nomenclature) : _getTitleNomenclature($PDOdb, $TAssetOFLine->fk_nomenclature)
			$stock_tomake = TAssetOF::getProductStock($product->id);

			$TLine= array(
				'id'=>$TAssetOFLine->getId()
				,'idprod'=>$form->hidden('TAssetOFLine['.$k.'][fk_product]', $product->id)
				,'lot_number'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][lot_number]', $TAssetOFLine->lot_number, 15,50,'type_product="TO_MAKE" fk_product="'.$product->id.'"','TAssetOFLineLot') : $TAssetOFLine->lot_number
				,'libelle'=>$product->getNomUrl(1).' '.$product->label.' - '.$langs->trans("Stock")." : "
				        .$stock_tomake._fiche_ligne_asset($PDOdb,$form, $of, $TAssetOFLine, 'TO_MAKE')
			        ,'nomenclature'=>$nomenclature
				,'addneeded'=> ($form->type_aff=='edit' && $of->status=='DRAFT') ? '<a href="#null" statut="'.$of->status.'" onclick="updateQtyNeededForMaking('.$of->getId().','.$TAssetOFLine->getId().',this);">'.img_picto($langs->trans('UpdateNeededQty'), 'object_technic.png').'</a>' : ''
				,'qty'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][qty]', $TAssetOFLine->qty, 5,5,'','').$conditionnement_label_edit : $TAssetOFLine->qty.$conditionnement_label
				,'qty_used'=>($of->status=='OPEN' || $of->status=='CLOSE') ? $form->texte('', 'TAssetOFLine['.$k.'][qty_used]', $TAssetOFLine->qty_used, 5,5,'','').$conditionnement_label_edit : $TAssetOFLine->qty_used.$conditionnement_label
				,'fk_product_fournisseur_price' => $form->combo('', 'TAssetOFLine['.$k.'][fk_product_fournisseur_price]', $Tab, ($TAssetOFLine->fk_product_fournisseur_price != 0) ? $TAssetOFLine->fk_product_fournisseur_price : $selected, 1, '', 'style="max-width:250px;"')
				,'delete'=> ($form->type_aff=='edit' && $of->status=='DRAFT') ? '<a href="#null" onclick="deleteLine('.$TAssetOFLine->getId().',\'TO_MAKE\');">'.img_picto($langs->trans('Delete'), 'delete.png').'</a>' : ''
				,'fk_entrepot' => !empty($conf->global->ASSET_MANUAL_WAREHOUSE) && ($of->status == 'DRAFT' || $of->status == 'VALID' || $of->status == 'NEEDOFFER' || $of->status == 'ONORDER' || $of->status == 'OPEN') && $form->type_aff == 'edit' ? $formProduct->selectWarehouses($TAssetOFLine->fk_entrepot, 'TAssetOFLine['.$k.'][fk_entrepot]', '', 0, 0, $TAssetOFLine->fk_product) : $TAssetOFLine->getLibelleEntrepot($PDOdb)
			);



			$action = $form->type_aff;
			$parameter=array('of'=>&$of, 'line'=>&$TLine,'type'=>'TO_MAKE');
			$res = $hookmanager->executeHooks('lineObjectOptions', $parameter, $TAssetOFLine, $action);

			if($res>0 && !empty($hookmanager->resArray)) {

				$TLine = $hookmanager->resArray;

			}

			$TRes[] = $TLine;
		}
	}

	return $TRes;
}

function _fiche_ligne_asset(&$PDOdb,&$form,&$of, &$assetOFLine, $type='NEEDED')
{
    global $conf,$langs;

    if(empty($conf->global->USE_LOT_IN_OF) || empty($conf->asset->enabled) ) return '';

    $TAsset = $assetOFLine->getAssetLinked($PDOdb);


    $r='<div>';

    if($of->status=='DRAFT' && $form->type_aff == 'edit' && $type=='NEEDED')
    {
    	$url = dol_buildpath('/of/fiche_of.php?id='.$of->getId().'&idLine='.$assetOFLine->getId().'&action=addAssetLink&idAsset=', 1);
		// Pour le moment au limite au besoin, la création reste en dure, à voir
		$r.=$form->texte('', 'TAssetOFLine['.$assetOFLine->getId().'][new_asset]', '', 10,255,' title="Ajouter un équipement" fk_product="'.$assetOFLine->fk_product.'" rel="add-asset" fk-asset-of-line="'.$assetOFLine->getId().'" ')
			.'<a href="" base-href="'.$url.'">'.img_right($langs->trans('Link')).'</a>'
			.'<br/>';
    }
    foreach($TAsset as &$asset)
    {
        $r .= $asset->getNomUrl(1, 1, 2);

        if($of->status=='DRAFT' && $form->type_aff == 'edit' && $type=='NEEDED')
        {
            $r.=' <a href="?id='.$of->getId().'&idLine='.$assetOFLine->getId().'&idAsset='.$asset->getId().'&action=deleteAssetLink">'.img_delete($langs->trans('DeleteLink')).'</a>';
        }
    }

    $r.='</div>';

    return $r;

}

function _fiche(&$PDOdb, &$assetOf, $mode='edit',$fk_product_to_add=0,$fk_nomenclature=0) {
	global $langs,$db,$conf,$user,$hookmanager;
	/***************************************************
	* PAGE
	*
	* Put here all code to build page
	****************************************************/

	$parameters = array('id'=>$assetOf->getId());
	$reshook = $hookmanager->executeHooks('doActions',$parameters,$assetOf,$mode);    // Note that $action and $object may have been modified by hook

	//pre($assetOf,true);
	llxHeader('',$langs->trans('OFAsset'),'','');
	print dol_get_fiche_head(ofPrepareHead( $assetOf, 'assetOF') , 'fiche', $langs->trans('OFAsset'));

	?><style type="text/css">
		#assetChildContener .OFMaster {

			background:#fff;
			-webkit-box-shadow: 4px 4px 5px 0px rgba(50, 50, 50, 0.52);
			-moz-box-shadow:    4px 4px 5px 0px rgba(50, 50, 50, 0.52);
			box-shadow:         4px 4px 5px 0px rgba(50, 50, 50, 0.52);

			margin-bottom:20px;
		}

	</style>
		<div class="OFContent" rel="<?php echo $assetOf->getId() ?>">	<?php

	$TPrixFournisseurs = array();

	//$form=new TFormCore($_SERVER['PHP_SELF'],'formeq'.$assetOf->getId(),'POST');

	//Affichage des erreurs
	if(!empty($assetOf->errors)){
		?>
		<br><div class="error">
		<?php
		foreach($assetOf->errors as $error){
			echo $error."<br>";
			setEventMessage($error,'errors');
		}
		$assetOf->errors = array();
		?>
		</div><br>
		<?php


	}

	$form=new TFormCore();
	$form->Set_typeaff($mode);

	$doliform = new Form($db);

	if(!empty($_REQUEST['fk_product'])) echo $form->hidden('fk_product', $_REQUEST['fk_product']);

	$TBS=new TTemplateTBS();
	$liste=new TListviewTBS('asset');

	$TBS->TBS->protect=false;
	$TBS->TBS->noerr=true;

	$PDOdb = new TPDOdb;

	$TNeeded = array();
	$TToMake = array();

	$TNeeded = _fiche_ligne($form, $assetOf, "NEEDED");
	$TToMake = _fiche_ligne($form, $assetOf, "TO_MAKE");

	$TIdCommandeFourn = $assetOf->getElementElement($PDOdb);

	$HtmlCmdFourn = '';

	if(count($TIdCommandeFourn)){
		foreach($TIdCommandeFourn as $idcommandeFourn){
			$cmd = new CommandeFournisseur($db);
			$cmd->fetch($idcommandeFourn);

			$HtmlCmdFourn .= $cmd->getNomUrl(1)." - ".$cmd->getLibStatut(0);
		}
	}

	ob_start();
	$doliform->select_produits('','fk_product','',$conf->product->limit_size,0,-1,2,'',3,array());
	$select_product = ob_get_clean();

	$Tid = array();
	//$Tid[] = $assetOf->rowid;
	if($assetOf->getId()>0) $assetOf->getListeOFEnfants($PDOdb, $Tid);

	$TWorkstation=array();
	foreach($assetOf->TAssetWorkstationOF as $k => &$TAssetWorkstationOF) {
		$ws = &$TAssetWorkstationOF->ws;

		$TWorkstation[]=array(
				'libelle'=>'<a href="'.dol_buildpath('workstation/workstation.php?id='.$ws->rowid.'&action=view', 1).'">'.$ws->name.'</a>'
				,'fk_user' => visu_checkbox_user($PDOdb, $form, $ws->fk_usergroup, $TAssetWorkstationOF->users, 'TAssetWorkstationOF['.$k.'][fk_user][]', $assetOf->status)
				,'fk_project_task' => visu_project_task($db, $TAssetWorkstationOF->fk_project_task, $form->type_aff, 'TAssetWorkstationOF['.$k.'][progress]')
				,'fk_task' => visu_checkbox_task($PDOdb, $form, $TAssetWorkstationOF->fk_asset_workstation, $TAssetWorkstationOF->tasks,'TAssetWorkstationOF['.$k.'][fk_task][]', $assetOf->status)
				,'nb_hour'=> ($assetOf->status=='DRAFT' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][nb_hour]', $TAssetWorkstationOF->nb_hour,3,10) : (($conf->global->ASSET_USE_CONVERT_TO_TIME ? convertSecondToTime($TAssetWorkstationOF->nb_hour * 3600) : price($TAssetWorkstationOF->nb_hour) ). (empty($user->rights->of->of->price) ? '' : ' x '. price($TAssetWorkstationOF->thm,0,'',1,-1,-1,$conf->currency) ))
				,'nb_hour_real'=>($assetOf->status=='OPEN' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][nb_hour_real]', $TAssetWorkstationOF->nb_hour_real,3,10) : (($conf->global->ASSET_USE_CONVERT_TO_TIME ? convertSecondToTime($TAssetWorkstationOF->nb_hour_real * 3600) : price($TAssetWorkstationOF->nb_hour_real)) . (empty($user->rights->of->of->price) ? '' : ' x '. price($TAssetWorkstationOF->thm,0,'',1,-1,-1,$conf->currency) ) )
				,'nb_days_before_beginning'=>($assetOf->status=='DRAFT' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][nb_days_before_beginning]', $TAssetWorkstationOF->nb_days_before_beginning,3,10) : $TAssetWorkstationOF->nb_days_before_beginning
				,'delete'=> ($mode=='edit' && $assetOf->status=='DRAFT') ? '<a href="javascript:deleteWS('.$assetOf->getId().','.$TAssetWorkstationOF->getId().');">'.img_picto($langs->trans('Delete'), 'delete.png').'</a>' : ''
				,'note_private'=>($assetOf->status=='DRAFT' && $mode == 'edit') ? $form->zonetexte('','TAssetWorkstationOF['.$k.'][note_private]', $TAssetWorkstationOF->note_private,50,1) : $TAssetWorkstationOF->note_private
				,'rang'=>($assetOf->status=='DRAFT' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][rang]', $TAssetWorkstationOF->rang,3,10)  : $TAssetWorkstationOF->rang
				,'id'=>$ws->getId()
		);

	}

	$client=new Societe($db);
	if($assetOf->fk_soc>0) $client->fetch($assetOf->fk_soc);

	$commande=new Commande($db);
	if($assetOf->fk_commande>0) $commande->fetch($assetOf->fk_commande);

	$TOFParent = array_merge(array(0=>'')  ,$assetOf->getCanBeParent($PDOdb));

	$hasParent = false;
	if (!empty($assetOf->fk_assetOf_parent))
	{
		$TAssetOFParent = new TAssetOF;
		$TAssetOFParent->load($PDOdb, $assetOf->fk_assetOf_parent);
		$hasParent = true;
	}

	$parameters = array('id'=>$assetOf->getId());
	$reshook = $hookmanager->executeHooks('formObjectOptions',$parameters,$assetOf,$mode);    // Note that $action and $object may have been modified by hook

	if($fk_product_to_add>0) {
		$product_to_add = new Product($db);
		$product_to_add->fetch($fk_product_to_add);

		$link_product_to_add = $product_to_add->getNomUrl(1).' '.$product_to_add->label;
		$quantity_to_create = $form->texte('', 'quantity_to_create', 1, 3, 255);
	}
	else{
		$link_product_to_add = '';
		$quantity_to_create = '';
	}

	$TTransOrdre = array_map(array($langs, 'trans'),  TAssetOf::$TOrdre);

	$TTransStatus = array_map(array($langs, 'trans'), TAssetOf::$TStatus);

	print $TBS->render('tpl/fiche_of.tpl.php'
		,array(
			'TNeeded'=>$TNeeded
			,'TTomake'=>$TToMake
			,'workstation'=>$TWorkstation
		)
		,array(
			'assetOf'=>array(
					'id'=> $assetOf->getId()
					,'numero'=> ($assetOf->getId() > 0) ? '<a href="fiche_of.php?id='.$assetOf->getId().'">'.$assetOf->getNumero($PDOdb).'</a>' : $assetOf->getNumero($PDOdb)
						,'ordre'=>$form->combo('','ordre',$TTransOrdre,$assetOf->ordre)
					,'fk_commande'=>($assetOf->fk_commande==0) ? '' : $commande->getNomUrl(1)
					//,'statut_commande'=> $commande->getLibStatut(0)
					,'commande_fournisseur'=>$HtmlCmdFourn
					,'date_besoin'=>$form->calendrier('','date_besoin',$assetOf->date_besoin,12,12)
					,'date_lancement'=>$form->calendrier('','date_lancement',$assetOf->date_lancement,12,12)
					,'temps_estime_fabrication'=>price($assetOf->temps_estime_fabrication,0,'',1,-1,2)
					,'temps_reel_fabrication'=>price($assetOf->temps_reel_fabrication,0,'',1,-1,2)

					,'fk_soc'=> ($mode=='edit') ? $doliform->select_company($assetOf->fk_soc,'fk_soc','client=1',1) : (($client->id) ? $client->getNomUrl(1) : '')
					,'fk_project'=>custom_select_projects(-1, $assetOf->fk_project, 'fk_project',$mode)

					,'note'=>$form->zonetexte('', 'note', $assetOf->note, 80,5)

					,'quantity_to_create'=>$quantity_to_create
					,'product_to_create'=>$link_product_to_add

					,'status'=>$form->combo('','status',$TTransStatus,$assetOf->status)
					,'statustxt'=>$TTransStatus[$assetOf->status]
					,'idChild' => (!empty($Tid)) ? '"'.implode('","',$Tid).'"' : ''
					,'url' => dol_buildpath('/of/fiche_of.php', 1)
					,'url_liste' => ($assetOf->getId()) ? dol_buildpath('/of/fiche_of.php?id='.$assetOf->getId(), 1) : dol_buildpath('/of/liste_of.php', 1)
					,'fk_product_to_add'=>$fk_product_to_add
					,'fk_nomenclature'=>$fk_nomenclature
					,'fk_assetOf_parent'=>($assetOf->fk_assetOf_parent ? $assetOf->fk_assetOf_parent : '')
					,'link_assetOf_parent'=>($hasParent ? '<a href="'.dol_buildpath('/of/fiche_of.php?id='.$TAssetOFParent->rowid, 1).'">'.$TAssetOFParent->numero.'</a>' : '')

					,'total_cost'=>price($assetOf->total_cost,0,'',1,-1,2, $conf->currency)
					,'total_estimated_cost'=>price($assetOf->total_estimated_cost,0,'',1,-1,2, $conf->currency)
					,'mo_cost'=>price($assetOf->mo_cost,0,'',1,-1,2, $conf->currency)
					,'mo_estimated_cost'=>price($assetOf->mo_estimated_cost,0,'',1,-1,2, $conf->currency)
					,'compo_cost'=>price($assetOf->compo_cost,0,'',1,-1,2, $conf->currency)
					,'compo_estimated_cost'=>price($assetOf->compo_estimated_cost,0,'',1,-1,2, $conf->currency)
					,'compo_planned_cost'=>price($assetOf->compo_planned_cost,0,'',1,-1,2, $conf->currency)
					,'current_cost_for_to_make'=>price($assetOf->current_cost_for_to_make,0,'',1,-1,2, $conf->currency)
			)
			,'view'=>array(
				'mode'=>$mode
				,'status'=>$assetOf->status
				,'allow_delete_of_finish'=>$user->rights->of->of->allow_delete_of_finish
				,'ASSET_USE_MOD_NOMENCLATURE'=>(int) $conf->nomenclature->enabled
				,'OF_MINIMAL_VIEW_CHILD_OF'=>(int)$conf->global->OF_MINIMAL_VIEW_CHILD_OF
				,'select_product'=>$select_product
				,'select_workstation'=>$form->combo('', 'fk_asset_workstation', TWorkstation::getWorstations($PDOdb), -1)
				//,'select_workstation'=>$form->combo('', 'fk_asset_workstation', TAssetWorkstation::getWorstations($PDOdb), -1) <= assetworkstation
				,'actionChild'=>($mode == 'edit')?__get('actionChild','edit'):__get('actionChild','view')
				,'use_lot_in_of'=>(int)(!empty($conf->asset->enabled) && !empty($conf->global->USE_LOT_IN_OF))
				,'use_project_task'=>(int) $conf->global->ASSET_USE_PROJECT_TASK
				,'defined_user_by_workstation'=>(int) $conf->global->ASSET_DEFINED_USER_BY_WORKSTATION
				,'defined_task_by_workstation'=>(int) $conf->global->ASSET_DEFINED_OPERATION_BY_WORKSTATION
				,'defined_workstation_by_needed'=>(int) $conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED
				,'defined_manual_wharehouse'=>(int) $conf->global->ASSET_MANUAL_WAREHOUSE
				,'hasChildren' => (int) !empty($Tid)
				,'user_id'=>$user->id
				,'workstation_module_activate'=>(int) $conf->workstation->enabled
				,'show_cost'=>(int)$user->rights->of->of->price
				,'langs'=>$langs
				,'editField'=>($form->type_aff == 'view' ? '<a class="notinparentview quickEditButton" href="#" onclick="quickEditField('.$assetOf->getId().',this)" style="float:right">'.img_edit().'</a>' : '')
			)
			,'rights'=>array(
				'show_ws_time'=>$user->rights->of->of->show_ws_time
			)
		)
	);

	echo $form->end_form();

	llxFooter('$Date: 2011/07/31 22:21:57 $ - $Revision: 1.19 $');
}

function _fiche_ligne_control(&$PDOdb, $fk_assetOf, $assetOf=-1)
{
	$res = array();

	if ($assetOf == -1)
	{
		$sql = 'SELECT rowid as id, libelle, question, type, "" as response, "" as id_assetOf_control FROM '.MAIN_DB_PREFIX.'asset_control WHERE rowid NOT IN (SELECT fk_control FROM '.MAIN_DB_PREFIX.'assetOf_control WHERE fk_assetOf ='.(int) $fk_assetOf.')';
	}
	else
	{
		if (empty($assetOf->TQualityControlAnswer)) return $res;

		$ids = array();
		foreach ($assetOf->TQualityControlAnswer as $ofControl)
		{
			$ids[] = $ofControl->getId();
		}

		$sql = 'SELECT c.rowid as id, c.libelle, c.question, c.type, ofc.response, ofc.rowid as id_assetOf_control FROM '.MAIN_DB_PREFIX.'asset_control c';
		$sql.= ' INNER JOIN '.MAIN_DB_PREFIX.'assetOf_control ofc ON (ofc.fk_control = c.rowid)';
		$sql.= ' WHERE ofc.rowid IN ('.implode(',', $ids).')';

	}

	$PDOdb->Execute($sql);
	while ($PDOdb->Get_line())
	{
		$res[] = array(
			'id' => $PDOdb->Get_field('id')
			,'libelle' => '<a href="'.DOL_URL_ROOT.'/custom/asset/control.php?id='.$PDOdb->Get_field('id').'">'.$PDOdb->Get_field('libelle').'</a>'
			,'type' => TQualityControl::$TType[$PDOdb->Get_field('type')]
			,'action' => '<input type="checkbox" value="'.$PDOdb->Get_field('id').'" name="TControl[]" />'
			,'question' => $PDOdb->Get_field('question')
			,'response' => ($assetOf == -1 ? '' : $assetOf->generate_visu_control_value($PDOdb->Get_field('id'), $PDOdb->Get_field('type'), $PDOdb->Get_field('response'), 'TControlResponse['.$PDOdb->Get_field('id_assetOf_control').'][]'))
			,'delete' => '<input type="checkbox" value="'.$PDOdb->Get_field('id_assetOf_control').'" name="TControlDelete[]" />'
		);
	}

	return $res;
}

function _fiche_control(&$PDOdb, &$assetOf)
{
	global $langs,$db,$conf;

	llxHeader('',$langs->trans('OFAsset'),'','');
	print dol_get_fiche_head(ofPrepareHead( $assetOf, 'assetOF') , 'controle', $langs->trans('OFAsset'));

	/******/
	$TBS=new TTemplateTBS();
	$TBS->TBS->protect=false;
	$TBS->TBS->noerr=true;

	$form=new TFormCore($_SERVER['PHP_SELF'], 'form', 'POST');
	$form->Set_typeaff('view');

	$TControl = _fiche_ligne_control($PDOdb, $assetOf->getId());
	$TQualityControlAnswer = _fiche_ligne_control($PDOdb, $assetOf->getId(), $assetOf);

	print $TBS->render('tpl/fiche_of_control.tpl.php'
		,array(
			'TControl'=>$TControl
			,'TQualityControlAnswer'=>$TQualityControlAnswer
		)
		,array(
			'assetOf'=>array(
				'id'=>(int) $assetOf->getId()
			)
			,'view'=>array(
				'nbTControl'=>count($TControl)
				,'nbTQualityControlAnswer'=>count($TQualityControlAnswer)
				,'url'=>DOL_URL_ROOT.'/custom/of/fiche_of.php'
			)
		)
	);

	$form->end();

	/******/

	llxFooter('$Date: 2011/07/31 22:21:57 $ - $Revision: 1.19 $');
}


function concatPDFOF(&$pdf,$files) {

	foreach($files as $file)
	{
		$pagecount = $pdf->setSourceFile($file);

		for ($i = 1; $i <= $pagecount; $i++) {
			$tplidx = $pdf->ImportPage($i);
			$s = $pdf->getTemplatesize($tplidx);
			$pdf->AddPage($s['h'] > $s['w'] ? 'P' : 'L');
			$pdf->useTemplate($tplidx);
		}

	}

	return $pagecount;
}
