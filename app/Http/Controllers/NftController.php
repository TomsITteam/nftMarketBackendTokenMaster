<?php

namespace App\Http\Controllers;

//use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

use App\Models\Nft;

use App\Models\PayToken;

use App\Models\NftLikeCun;

use App\Models\Profile;

use App\Models\CacheNft;

use App\Models\LogTxVerify;

use App\Models\CategoryEnv;

use App\Models\LayerType;
use App\Models\LayerValue;

use BlockSDK;
use IPFS;

//envDB('HAS_MULTI_NET') == 1
if(envDB('HAS_MULTI_NET') == 1){

	//다중 메인넷
	class NetNftController extends Controller {
		use Modules\MultiNetNftController;
	}

}else{

	//싱글 메인넷
	class NetNftController extends Controller {
		use Modules\SingleNetNftController;
	}

}

class NftController extends NetNftController
{

	public function rtrimPirce($price){
		if(strpos($price,".") > 0){
			$price = rtrim($price,0);
			$price = rtrim($price,'.');
		}

		return $price;
	}

	public function saleTokens(Request $request,$seller_address){
		if(empty($request->offset) == true){
			$offset = 0;
		}else{
			$offset = $request->offset;
		}

		$tokens721 = $this->getSaleNfts($seller_address,$offset,6);
		$tokens1155 = $this->getMultiSaleNfts($seller_address,$offset,6);

		if(empty($tokens721) == true){
			$tokens = $tokens1155['data'];
			$total = $tokens1155['total'];
		}if(empty($tokens1155) == true){
			$tokens = $tokens721['data'];
			$total = $tokens721['total'];
		}else{
			$tokens = array_merge($tokens721['data'], $tokens1155['data']);
			$total = max($tokens721['total'], $tokens1155['total']);
		}
		//$total = count($tokens);

		foreach ((array) $tokens as $key => $value){
			$sort[$key] = $value['timestamp'];
		}
		if(empty($tokens) == false){
			array_multisort($sort, SORT_DESC, $tokens);
		}

		return [
			'total' => $total,
			'data' => $tokens
		];
	}

	public function auctionEnding(Request $request){
		$tokens = $this->getAuctionNfts(0,4);

		$tokens = array_slice($tokens,0,4);
		return response()->json($tokens);
	}

	public function newTokens(Request $request){
		$new721 = $this->getNfts(0,8);
		$new1155 = $this->getMultiNfts(0,8);

		if(empty($new721) == true){
			$tokens = $new1155;
		}if(empty($new1155) == true){
			$tokens = $new721;
		}else{
			$tokens = array_merge($new721, $new1155);
		}
		foreach ((array) $tokens as $key => $value){
			$sort[$key] = $value['timestamp'];
		}
		if(empty($tokens) == false){
			array_multisort($sort, SORT_DESC, $tokens);
		}
		$tokens = array_slice($tokens,0,8);

		return response()->json($tokens);
	}

	public function hexToBool($hex){
		return (bool)hexdec(substr($hex,0,64));
	}

	public function hexToDec($hex){
		return $this->bchexdec(substr($hex,0,64));
	}

	public function hexToOffer($hex){
		$isForSale = (bool)hexdec(substr($hex,0,64));
		$seller = substr($hex,64,64);
		$minValue = $this->bchexdec(substr($hex,128,64));
		$endTime = hexdec(substr($hex,192,64));
		$tokenAddress = '0x' . substr(substr($hex,256,64),24);

		return [
			'isForSale'		=> $isForSale,
			'seller'		=> $seller,
			'minValue'		=> $minValue,
			'endTime'		=> $endTime,
			'tokenAddress' 	=> $tokenAddress,
		];
	}

	public function hexToBid($hex){
		$hasBid = (bool)hexdec(substr($hex,0,64));
		$bidder = substr($hex,64,64);
		$value = hexdec(substr($hex,128,64));

		return [
			'hasBid' => $hasBid,
			'bidder' => '0x' . substr($bidder,-40),
			'value' => $value
		];
	}

	public function getCache($id){
		$cache = CacheNft::find($id);
		if(empty($cache) == true){
			return false;
		}else if((strtotime($cache->updated_at) + envDB('CACHE_TIME_NFT')) < time() ){
			return false;//지정된 캐시시간보다 길어졋을경우
		}
		return CacheNft::find($id);
	}

	public function cacheSave($id,$hex){
		$cacheNft = CacheNft::find($id);
		if(empty($cacheNft) == true){
			$cacheNft = new CacheNft();
		}

		$cacheNft->id = $id;
		$cacheNft->data = $hex;
		$cacheNft->updated_at = date('Y-m-d H:i:s');
		$cacheNft->save();
	}

	public function tokenAmount($tokenAddress,$amount){
		$payToken = PayToken::where('tokenAddress',$tokenAddress)->first();
		if(empty($payToken) == true){
			return $amount;
		}

		for($i=0;$i<$payToken['decimals'];$i++){
			$amount = bcdiv('' . $amount,'10',$payToken['decimals']);
		}

		return $this->rtrimPirce($amount);
	}

	public function getCacheMultiTokenInfo($token_id){
		$result = $this->getCache('multitokeninfo_' . $token_id);
		if(empty($result) == true){
			return $this->getMultiTokenInfo($token_id);
		}

		return json_decode($result->data,true);
	}

    public function bchexdec($hex) {
        if(strlen($hex) == 1) {
            return hexdec($hex);
        } else {
            $remain = substr($hex, 0, -1);
            $last = substr($hex, -1);
            return bcadd(bcmul(16, $this->bchexdec($remain)), hexdec($last));
        }
    }


	public function getProfile($address){
		$profile = Profile::where("address",$address)->first();
		if(empty($profile) == true){
			$profile = [
				'address' => $address,
				'avatar'  => envDB('BASE_IMAGE_URI') . '/img/profile.svg',
				'auth' => 0
			];
		}else{
			$profile = [
				'address' => $address,
				'avatar'  => $profile->avatar(),
				'name'    => $profile->name,
				'nick'    => $profile->nick,
				'auth'    => $profile->auth
			];
		}

		if(empty($profile['name']) == true){
			$profile['name'] = $address;
		}
		if(empty($profile['nick']) == true){
			$profile['nick'] = $address;
		}

		return $profile;
	}

	public function holdingTokens(Request $request,$owner_address){
		if(empty($request->offset) == true){
			$offset = 0;
		}else{
			$offset = $request->offset;
		}

		$tokens721 = $this->getHoldNfts($owner_address,$offset,6);
		$tokens1155 = $this->getMultiHoldNfts($owner_address,$offset,6);

		if(empty($tokens721) == true){
			$tokens = $tokens1155['data'];
			$total = $tokens1155['total'];
		}if(empty($tokens1155) == true){
			$tokens = $tokens721['data'];
			$total = $tokens721['total'];
		}else{
			$tokens = array_merge($tokens721['data'], $tokens1155['data']);
			$total = max($tokens721['total'], $tokens1155['total']);
		}

		foreach ((array) $tokens as $key => $value){
			$sort[$key] = $value['timestamp'];
		}
		if(empty($tokens) == false){
			array_multisort($sort, SORT_DESC, $tokens);
		}

		return [
			'total' => $total,
			'data' => $tokens
		];
	}

	public function getTokenData($nft){
		$token['id'] = $nft->id;
		$token['net'] = $nft->net;
		$token['token_id'] = $nft->token_id;
		$token['name'] = $nft->name;
		$token['description'] = $nft->description;
		$token['timestamp'] = strtotime($nft->created_at);
		$token['interface'] = $nft->interface;
        $token['thumbnail'] = empty($nft->thumbnail) ? "" : envDB('BASE_AWS_S3_URI') . '/nft-files/' . $nft->thumbnail;

		if(substr($nft->file_name,-4) == '.mp4'){
			if(empty(envDB('IS_AWS_S3')) == true){
				$token['video_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
			}else if(empty(envDB('IS_AWS_S3')) == false && file_exists(storage_path('app/public/nft_files/' . $nft->file_name)) == true ){
				$token['video_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
			}else if(empty(envDB('IS_AWS_S3')) == false){
				$token['video_url'] = envDB('BASE_AWS_S3_URI') . '/nft-files/' . $nft->file_name;
			}
		}else if(substr($nft->file_name,-4) == '.mp3'){
            if(empty(envDB('IS_AWS_S3')) == true){
                $token['music_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
            }else if(empty(envDB('IS_AWS_S3')) == false && file_exists(storage_path('app/public/nft_files/' . $nft->file_name)) == true ){
                $token['music_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
            }else if(empty(envDB('IS_AWS_S3')) == false){
                $token['music_url'] = envDB('BASE_AWS_S3_URI') . '/nft-files/' . $nft->file_name;
            }

            $token['is_music'] = true;
        }else{
			if(empty(envDB('IS_AWS_S3')) == true){
				$token['image_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
			}else if(empty(envDB('IS_AWS_S3')) == false && file_exists(storage_path('app/public/nft_files/' . $nft->file_name)) == true ){
				$token['image_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
			}else if(empty(envDB('IS_AWS_S3')) == false){
				$token['image_url'] = envDB('BASE_AWS_S3_URI') . '/nft-files/' . $nft->file_name;
			}
		}

		if(empty($nft->tx_hash) == true){
			$token['owner'] = $this->getProfile($nft->creator_address);
			$token['price'] = 0;
			$token['offer'] = [];
			$token['like'] = 0;
		}else{
			if(envDB('HAS_MULTI_NET') == 1){
				$tokenInfo = $this->getCacheTokenInfo($nft->net,$nft->token_id,$nft->id);
				$token['listed'] = $this->getCacheListed($nft->net,$nft->token_id);

				if($token['listed'] == false){
					$token['offer'] = $this->getCacheOffer($nft->net,$nft->token_id);

					if($token['offer']['isForSale'] == true){
						$token['bid'] = $this->getCacheBid($nft->net,$nft->token_id);
					}
					$tokenAddress = $token['offer']['tokenAddress'];
					$token['price'] = $token['offer']['minValue'];

				}else{
					$tokenAddress = $this->getCacheSingleNftSaleTokenAddress($nft->net,$nft->token_id);
					$token['price'] = $this->getCachePrice($nft->net,$nft->token_id);
				}


				$payToken = PayToken::where('net',$nft->net)->where('tokenAddress',$tokenAddress)->first();
			}else{
				$tokenInfo = $this->getCacheTokenInfo($nft->token_id,$nft->id);
				$token['listed'] = $this->getCacheListed($nft->token_id);

				if($token['listed'] == false){
					$token['offer'] = $this->getCacheOffer($nft->token_id);

					if($token['offer']['isForSale'] == true){
						$token['bid'] = $this->getCacheBid($nft->token_id);
					}
					$tokenAddress = $token['offer']['tokenAddress'];
					$token['price'] = $token['offer']['minValue'];

				}else{
					$tokenAddress = $this->getCacheSingleNftSaleTokenAddress($nft->token_id);
					$token['price'] = $this->getCachePrice($nft->token_id);
				}


				$payToken = PayToken::where('tokenAddress',$tokenAddress)->first();
			}
			$token['owner'] = $this->getProfile($tokenInfo['owner']);


			for($i=0;$i<$payToken['decimals'];$i++){
				$token['price'] = bcdiv('' . $token['price'],'10',$payToken['decimals']);

				if(empty($token['offer']['isForSale']) == false){
					$token['offer']['minValue'] = bcdiv('' . $token['offer']['minValue'],'10',$payToken['decimals']);
				}
			}
			if(empty($token['offer']['minValue']) == false){
				$token['offer']['minValue'] = rtrim($token['offer']['minValue'],0);
				$token['offer']['minValue'] = rtrim($token['offer']['minValue'],'.');
			}
			$token['price'] = rtrim($token['price'],0);
			$token['price'] = rtrim($token['price'],'.');
			$token['token']  = $payToken;
			$token['like'] = $this->likeCun($nft->token_id);
		}

		return $token;
	}

	public function getMultiTokenData($nft){
		$token['id'] = $nft->id;
		$token['net'] = $nft->net;
		$token['token_id'] = $nft->token_id;
		$token['name'] = $nft->name;
		$token['description'] = $nft->description;
		$creator = $this->getProfile($nft->creator_address);
		$token['creator'] = $creator;
		$token['timestamp'] = strtotime($nft->created_at);
		$token['interface'] = $nft->interface;
        $token['thumbnail'] = empty($nft->thumbnail) ? "" : envDB('BASE_AWS_S3_URI') . '/nft-files/' . $nft->thumbnail;

		if(substr($nft->file_name,-4) == '.mp4'){
			if(empty(envDB('IS_AWS_S3')) == true){
				$token['video_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
			}else if(empty(envDB('IS_AWS_S3')) == false && file_exists(storage_path('app/public/nft_files/' . $nft->file_name)) == true ){
				$token['video_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
			}else if(empty(envDB('IS_AWS_S3')) == false){
				$token['video_url'] = envDB('BASE_AWS_S3_URI') . '/nft-files/' . $nft->file_name;
			}
		}else if(substr($nft->file_name,-4) == '.mp3') {
            if (empty(envDB('IS_AWS_S3')) == true) {
                $token['music_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
            } else if (empty(envDB('IS_AWS_S3')) == false && file_exists(storage_path('app/public/nft_files/' . $nft->file_name)) == true) {
                $token['music_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
            } else if (empty(envDB('IS_AWS_S3')) == false) {
                $token['music_url'] = envDB('BASE_AWS_S3_URI') . '/nft-files/' . $nft->file_name;
            }
            $token['is_music'] = true;
        }else{
			if(empty(envDB('IS_AWS_S3')) == true){
				$token['image_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
			}else if(empty(envDB('IS_AWS_S3')) == false && file_exists(storage_path('app/public/nft_files/' . $nft->file_name)) == true ){
				$token['image_url'] = envDB('BASE_IMAGE_URI') . Storage::url('nft_files/' . $nft->file_name);
			}else if(empty(envDB('IS_AWS_S3')) == false){
				$token['image_url'] = envDB('BASE_AWS_S3_URI') . '/nft-files/' . $nft->file_name;
			}
		}

		if(empty($nft->tx_hash) == true){
			$token['price'] = 0;
			$token['like'] = 0;
		}else{
			if(envDB('HAS_MULTI_NET') == 1){
				$transfer = $this->getCacheTransfer($nft->net,$nft->tx_hash);
				$createMap = $this->getCacheMultiNftSaleMap($nft->net,$nft->token_id,$transfer[0]['from']['address']);
				$payToken = PayToken::where('net',$nft->net)->where('tokenAddress',ltrim($createMap['tokenAddress']))->first();
			}else{
				$transfer = $this->getCacheTransfer($nft->tx_hash);
				$createMap = $this->getCacheMultiNftSaleMap($nft->token_id,$transfer[0]['from']['address']);
				$payToken = PayToken::where('tokenAddress',ltrim($createMap['tokenAddress']))->first();
			}
			
			$creator['sell_amount'] = $this->bchexdec(substr($transfer[0]['input'],138,64));
			$creator['price'] = $this->bchexdec(substr($transfer[0]['input'],202,64));
			for($i=0;$i<$payToken['decimals'];$i++){
				$creator['price'] = bcdiv($creator['price'],10,$payToken['decimals']);
			}

			$token['token'] = $payToken;
			$token['price'] =  $this->rtrimPirce($creator['price']);
			$token['token_amount'] = $nft->token_amount;
			$token['tx_hash'] = $nft->tx_hash;
		}

		return $token;
	}

	public function createdTokens(Request $request,$creator_address){
		if(empty($request->offset) == true){
			$offset = 0;
		}else{
			$offset = $request->offset;
		}

		if(empty($request->limit) == true){
			$limit = 8;
		}else{
			$limit = $request->limit;
		}

		$total = Nft::where('creator_address',$creator_address)->count();
		$nfts = Nft::where('creator_address',$creator_address)->orderBy('created_at','desc')->offset($offset)->limit($limit)->get();
		$tokens = [];
		foreach($nfts as $nft){
			if($nft['interface'] == 'erc1155'){
				$token = $this->getMultiTokenData($nft);
			}else{
				$token = $this->getTokenData($nft);
			}

			array_push($tokens,$token);
		}

		if($request->limit == 4){
			$tokens = array_slice($tokens,0,4);
		}

		return response()->json([
			'total' => $total,
			'data' => $tokens
		]);
	}

    public function createdVerify($request){
		if(empty($request->layers) == true && ($request->hasFile('nft_file') == false || $request->file('nft_file')->isValid() == false)){
			return response()->json([
				'error' => [
					'message' => "파일을 업로드하여 주시길 바랍니다"
				]
			]);
		}else if(empty($request->layers) == false && gettype($request->layers) == 'Array'){
			return response()->json([
				"error" => [
					"message" => "layers의 타입은 Array 형태 여야 합니다"
				]
			]);
		}else if(empty($request->nft_name) == true){
			return response()->json([
				'error' => [
					'message' => "이름을 입력해주시길 바랍니다"
				]
			]);
		}else if(empty($request->nft_description) == true){
			return response()->json([
				'error' => [
					'message' => "설명을 입력해주시길 바랍니다"
				]
			]);
		}else if(mb_strlen($request->nft_name,'utf-8') > 30){
			return response()->json([
				'error' => [
					'message' => "이름은 30자를 초과할수 없습니다"
				]
			]);
		}else if(mb_strlen($request->nft_description,'utf-8') > 300){
			return response()->json([
				'error' => [
					'message' => "설명은 300자를 초과할수 없습니다"
				]
			]);
		}
        $extension = strtolower($request->nft_file->clientExtension());
        if ($extension == "mp3"){
            if(empty($request->nft_thumbnail) == true){
                return response()->json([
                    'error' => [
                        'message' => "mp3 업로드시 섬네일은 필수입니다."
                    ]
                ]);
            }
        }

		$categoryEnv = CategoryEnv::find($request->nft_category);
		if(empty($categoryEnv) == true){
			return response()->json([
				'error' => [
					'message' => "잘못된 카테고리 입니다"
				]
			]);
		}

		$profile = Profile::where('address',$request->auth_address)->first();
		if(empty($profile) == true){
			$auth_profile = false;
		}else{
			if($profile->auth == 1){
				$auth_profile = true;
			}else{
				$auth_profile = false;
			}
		}


		if(empty($request->nft_file) == false && $request->nft_file != null && $request->nft_file != 'null'){
			$extension = strtolower($request->nft_file->clientExtension());

			if($extension != 'm4v' && $extension != 'jpg' && $extension != 'png' && $extension != 'gif' && $extension != 'mp4' && $extension != "mp3"){
				return response()->json([
					'error' => [
						'message' => "허가된 파일 확장자가 아닙니다," . $request->nft_file->clientExtension()
					]
				]);
			}

			$UPLOAD_SIZE_AUTH_AUTHORS = envDB('UPLOAD_SIZE_AUTH_AUTHORS');
			$UPLOAD_SIZE_UNAUTH_AUTHORS = envDB('UPLOAD_SIZE_UNAUTH_AUTHORS');

			if($auth_profile == true && $request->file('nft_file')->getSize() > $UPLOAD_SIZE_AUTH_AUTHORS){
				if($UPLOAD_SIZE_AUTH_AUTHORS >= 1000000){
					$size = round($UPLOAD_SIZE_AUTH_AUTHORS / 1000000,1);
					$size .= 'MB';
				}else{
					$size = $UPLOAD_SIZE_AUTH_AUTHORS / 1000;
					$size .= 'KB';
				}

				return response()->json([
					'error' => [
						'message' => "인증된 저자는 {$size} 이하의 파일만 업로드하실수 있습니다"
					]
				]);
			}
			if($auth_profile == false && $request->file('nft_file')->getSize() > $UPLOAD_SIZE_UNAUTH_AUTHORS){
				if($UPLOAD_SIZE_UNAUTH_AUTHORS >= 1000000){
					$size = round($UPLOAD_SIZE_UNAUTH_AUTHORS / 1000000,1);
					$size .= 'MB';
				}else{
					$size = $UPLOAD_SIZE_UNAUTH_AUTHORS / 1000;
					$size .= 'KB';
				}

				return response()->json([
					'error' => [
						'message' => "미인증 저자는 {$size} 이하의 파일만 업로드하실수 있습니다"
					]
				]);
			}
		}


		$UPLOAD_FILTER_ADDRESS = strtolower(envDB('UPLOAD_FILTER_ADDRESS'));
		if(strlen($UPLOAD_FILTER_ADDRESS) > 40 && strpos($UPLOAD_FILTER_ADDRESS,strtolower($request->auth_address)) === false){
			return response()->json([
				'error' => [
					'message' => "현재 관리자에게 승인받은 특정 주소만 업로드를 허용중 입니다"
				]
			]);
		}

		$UPLOAD_FILTER_TEXT = explode(',',envDB('UPLOAD_FILTER_TEXT'));
		foreach($UPLOAD_FILTER_TEXT as $text){
			if(empty($text) == true){
				continue;
			}
			if(strpos($request->nft_name,$text) !== false || strpos($request->nft_description,$text) !== false){
				return response()->json([
					'error' => [
						'message' => "금지된 단어가 발견되었습니다 [{$text}]"
					]
				]);
			}

		}


		$UPLOAD_FILTER_IP = explode(',',envDB('UPLOAD_FILTER_IP'));
		if(empty($UPLOAD_FILTER_IP) == false){

			foreach($UPLOAD_FILTER_IP as $benIP){
				if(empty($benIP) == true){
					continue;
				}

				$ip = $_SERVER["REMOTE_ADDR"];
				if(strpos($ip,$benIP) !== false){
					return response()->json([
						'error' => [
							'message' => "차단된 IP 입니다"
						]
					]);
				}
			}
		}


		if(empty($request->layers) == false){
			$UPLOAD_FILTER_LAYER = explode(',',strtolower(envDB('UPLOAD_FILTER_LAYER')));

			$check = false;
			foreach($UPLOAD_FILTER_LAYER as $address){
				if(empty($address) == true){
					continue;
				}

				if($address == $request->auth_address){
					$check = true;
				}
			}

			if(empty($check) == true){
				return response()->json([
						'error' => [
							'message' => "레이어 업로드 허용되지 않은 주소 입니다"
						]
				]);
			}
		}





		return false;
	}

	public function layersToAttributes($layers){
		$priorityAttributes = [];

		foreach($layers as $layer){
			$layer = json_decode($layer,true);
			$layerType = LayerType::where('name',$layer['type'])->first();
			if(empty($layerType) == true){
				return false;
			}

			$layerValue = LayerValue::where('type',$layer['type'])->where('value',$layer['value'])->first();
			if(empty($layerValue) == true){
				return false;
			}

			$priorityAttributes[$layerType->priority] = [
				'trait_type' => $layerValue->type,
				'value' => $layerValue->value,
			];
		}

		$attributes = [];
		foreach($priorityAttributes as $priorityAttribute){
			array_push($attributes,$priorityAttribute);
		}

		return $attributes;
	}

	public function created(Request $request){


		$verify = $this->createdVerify($request);
		if(empty($verify) == false){
			return $verify;
		}

		if(empty($request->nft_file) == false && $request->nft_file != null && $request->nft_file != 'null'){
			$extension = strtolower($request->nft_file->getClientOriginalExtension());
		}else{
			$extension = "png";
		}


		$filename = Str::random(30) . "." . $extension;
//		$filename = Str::random(30) . "." . $request->nft_file->extension();



		if(empty($request->layers) == false){
			$attributes = $this->layersToAttributes($request->layers);
			if(empty($attributes) == true){
				return response()->json([
					"error" => [
						"message" => "잘못된 레이어 정보"
					]
				]);
			}
		}


		if(empty($request->layers) == false && (empty($request->nft_file) == true || $request->nft_file == 'null')){
			$layerImageFile = "";
			foreach($attributes as $attribute){
				$layerImageFile .= $attribute['trait_type'] . $attribute['value'];
			}

			$layerImageFile = md5($layerImageFile) . ".png";
			$path = storage_path('app/public/layer-images/' . $layerImageFile);
			$result = Storage::disk('s3')->put("/nft-files/" . $filename,file_get_contents($path),'public');
			if(!$result){
				return response()->json([
					"error" => [
						"message" => "레이어 파일 저장에 실패 하였습니다"
					]
				]);
			}
		}else if(empty(envDB('IS_AWS_S3')) == true){
			$path = $request->nft_file->storeAs('nft_files',$filename,'public');
			if($path == false){
				return response()->json([
					'error' => [
						'message' => "파일 저장에 실패하였 습니다"
					]
				]);
			}

			$path = storage_path('app/public/'.$path);
		}else{
            $path = $request->nft_file->path();

            // jpg, jpeg, png 리사이징하여 저장하는 부분
            $result = $this->saveResizeFile($request->file('nft_file'), 1920, "nft-files");
            if ($result == false){
				return response()->json([
					'error' => [
						'message' => __('error.file_save')
					]
				]);
            }

            $filename = $result;

			$ext = Str::lower($request->file('nft_file')->getClientOriginalExtension());
			if (empty($request->nft_thumbnail) == false && ($ext == "mp4" || $ext == "mp3")){
				$thumbnail = $this->saveResizeFile($request->file('nft_thumbnail'), 500, "nft-files");
				if(empty($thumbnail) == true){
					return response()->json([
						'error' => [
							'message' => __('error.file_save')
						]
					]);
				}
			}
		}
		if(empty($thumbnail) == true){
			$thumbnail = "";
		}

        $ext = Str::lower($request->file('nft_file')->getClientOriginalExtension());
        if ($ext == "mp3"){
            // mp3
            $mp3 = IPFS::add(fopen($path, 'r'));
            //섬넬 이미지
            $thumbnail_ipfs = IPFS::add(fopen($request->nft_thumbnail->path(), 'r'));

        }else {
            $imageIPFS = IPFS::add(fopen($path, 'r'));
        }


		if(empty($imageIPFS['Hash']) == true && empty($mp3['Hash']) == true && empty($thumbnail_ipfs['Hash']) == true){
			return response()->json([
				'error' => [
					'message' => "IPFS에 파일 업로드 실패"
				]
			]);
		}

        if ($ext == "mp3"){
            //IPFS::pin($mp3['Hash']);
            //IPFS::pin($thumbnail_ipfs['Hash']);


            $metadata = [
                "name" => $request->nft_name,
                "description" => $request->nft_description,
                "animation_url" => "ipfs://" . $mp3['Hash'],
                "image" => "ipfs://" . $thumbnail_ipfs['Hash']
            ];

        }else {
            //IPFS::pin($imageIPFS['Hash']);

            $metadata = [
                "name" => $request->nft_name,
                "description" => $request->nft_description,
                "image" => "ipfs://" . $imageIPFS['Hash']
            ];
        }


        // 일반사용자 속성 추가일 경우 $metadata attributes값 추가
        if (empty($request->general_layers_type) == false){
            $tempArray = [];
            $g_layer_type = explode(',', $request->general_layers_type);
            $g_layer_value = explode(',', $request->general_layers_value);

            for($i =0; $i<count($g_layer_type); $i++){
                $tmp['trait_type'] = $g_layer_type[$i];
                $tmp['value'] = $g_layer_value[$i];
                $tempArray[] = $tmp;
                unset($tmp);
            }

            $metadata['attributes'] = $tempArray;
        }


		if(empty($attributes) == false){
			$metadata['attributes'] = $attributes;
		}


		$metadataIPFS = IPFS::add(json_encode($metadata),'metadata.json',['pin' => true]);
		if(empty($metadataIPFS['Hash']) == true){
			return response()->json([
				'error' => [
					'message' => "IPFS에 메타데이터 업로드 실패"
				]
			]);
		}
		//IPFS::pin($metadataIPFS['Hash']);

		if(empty(envDB('IS_AWS_S3')) == false){
			unlink($path);
		}

        if($ext == "mp3"){
            exec("curl https://ipfs-hk.decoo.io/ipfs/" . $mp3['Hash'] . " > /dev/null 2>/dev/null &");
            exec("curl https://ipfs-hk.decoo.io/ipfs/" . $thumbnail_ipfs['Hash'] . " > /dev/null 2>/dev/null &");
        }else {
            exec("curl https://ipfs-hk.decoo.io/ipfs/" . $imageIPFS['Hash'] . " > /dev/null 2>/dev/null &");
        }

        exec("curl https://ipfs-hk.decoo.io/ipfs/" . $metadataIPFS['Hash'] . " > /dev/null 2>/dev/null &");


		$nft = Nft::find($metadataIPFS['Hash']);
		if(empty($nft) == false){
			return response()->json([
				'error' => [
					'message' => "이미 등록된 작품 입니다"
				]
			]);
		}


		$id = $metadataIPFS['Hash'];
		$nft = new Nft();
		$nft->id = $id;
		$nft->ipfs_image_hash = $ext=="mp3" ? $mp3['Hash'] : $imageIPFS['Hash'];
		$nft->creator_address = strtolower($request->auth_address);
		$nft->category = $request->nft_category;
		$nft->attributes = (empty($metadata['attributes']) == false) ? json_encode($metadata['attributes'], JSON_UNESCAPED_UNICODE) : null; // 메타데이터도 추가
        $nft->interface = '';
		$nft->name = $request->nft_name;
		$nft->description = $request->nft_description;
		$nft->file_name = $filename;
		$nft->creator_ip = getRemoteAddr();
		$nft->thumbnail = $thumbnail;
		$nft->save();

		return response()->json([
			'id' => $id
		]);
	}

	public function deleted(Request $request){
		if(empty($request->nft_id) == true){
			return response()->json([
				'error' => [
					'message' => "NFT ID 를 입력해주시길 바랍니다"
				]
			]);
		}

		$nft = NFT::find($request->nft_id);
		if(empty($nft) == true){
			return response()->json([
				'error' => [
					'message' => "NFT를 찾을수 없습니다"
				]
			]);
		}

		if(empty($nft->token_id) == true){
			if($nft->creator_address != $request->auth_address){
				return response()->json([
					'error' => [
						'message' => "미발행 NFT는 생성자만 삭제권한을 가지고 있습니다"
					]
				]);
			}
		}else{
			$tokenInfo = $this->getTokenInfo($nft->token_id,$nft->id);
			if($tokenInfo['owner'] != $request->auth_address){
				return response()->json([
					'error' => [
						'message' => "NFT의 소유자만 삭제권한을 가지고 있습니다"
					]
				]);
			}
		}

		$nft->delete();

		return response()->json([
			'deleted' => true
		]);
	}



	public function explorer721($nfts,$offset){
		$nfts = $nfts->orderBy('created_at','desc')->offset($offset)->limit(12)->get();

		$tokens = [];
		foreach($nfts as $nft){
			if($nft->interface != 'erc721'){
				continue;
			}
			$token = $this->getTokenData($nft);

			array_push($tokens,$token);
		}

		return $tokens;
	}

	public function explorer1155($nfts,$offset){
		$nfts = $nfts->orderBy('created_at','desc')->offset($offset)->limit(12)->get();

		$tokens = [];
		foreach($nfts as $nft){
			if($nft->interface != 'erc1155'){
				continue;
			}
			$token = $this->getMultiTokenData($nft);

			array_push($tokens,$token);
		}


		return $tokens;
	}

	public function explorer(Request $request,$tab){

		if(empty($request->offset) == true){
			$offset = 0;
		}else{
			$offset = $request->offset;
		}

		if($tab == 'all'){
			$nfts = Nft::where('tx_hash','!=','');
		}else{
			$nfts = Nft::where('tx_hash','!=','')->where('category',$tab);
		}
		if($tab == 'search' && empty($request->q) == false){
			$nfts = Nft::where('tx_hash','!=','')->where('name','like',"%{$request->q}%");
		}

		$total = $nfts->count();

		$explorer721 = $this->explorer721($nfts,$offset);
		$explorer1155 = $this->explorer1155($nfts,$offset);

		$tokens = array_merge($explorer721, $explorer1155);

		foreach ((array) $tokens as $key => $value){
			$sort[$key] = $value['timestamp'];
		}

		if(empty($tokens) == false){
			array_multisort($sort, SORT_DESC, $tokens);
		}

		return response()->json([
			'total' => $total,
			'data'  => $tokens
		]);
	}

	/*
	public function buymultitokens(Request $request,$nft_id){
		$nft = Nft::where('id',$nft_id)->where('interface','erc1155')->first();

		/*$owner = $this->netClient()->getMultiNftOwnerList([
				'contract_address' => envDB("MULTINFT_ADDRESS"),
				'token_id' => $nft->token_id,
		]);
		print_r($owner);
		exit;

		//foreach($owner['payload']['owner'] as $addr){
			$data = $this->netClient()->getContractRead([
				'contract_address' => '0xcee8faf64bb97a73bb51e115aa89c17ffa8dd167',
				'method' => 'allowance',
				'return_type' => 'bool',
				'parameter_type' => ['address','address'],
				'parameter_data' => [addr,envDB("MULTINFT_ADDRESS")]
			]);
		//}

		//print_r($data['payload']['hex']);
		print_r('0x'.ltrim(substr($data['payload']['hex'],194),0));
		exit;
	}*/
}

