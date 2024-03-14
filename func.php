<?php
/*
func=CityStoreListByEvent
列出這個活動的城市(如果這個城市沒有櫃點, 或該櫃點可登記數量為0,, 或該櫃點己經登記額滿, 或該櫃點藏就不會列出)及櫃點
output : OK(1=成功,0=失敦), cityRows(縣市資訊), storeRows(櫃點資訊)
=========================================================================
func=SubmitForm
接收登記者資料
input : c_no(縣市編號), s_no(櫃點編號), t_name(登記者姓名), t_mobile (登記者手機)
-------------------------------------------------------------------------
output : OK(0=失敗), status(1=有欄位未填 / 2=有欄位格式不符 / 3=縣市跟櫃點對不起來 / 4=己額滿 / 5=手機己登記,且仍在可領取時間內 / 6=己兌換 / 7=該手機是黑名單 / 8=Email己登記 / 9=不明錯誤)
output : fields(status=1 & 2 時才有, 列出那些欄位未填)
-------------------------------------------------------------------------
output : OK(1=成功), status(1=未登記過的登記者 / 2=手機己登記過,但己超過領取時間,所以可再次證記), t_no(登記者編號), token
=========================================================================
func=SentMms
發送簡訊
input : c_no(縣市編號), s_no(櫃點編號), t_no(登記者編號), token (由 SubmitForm 成功時所得到的 token)
output : OK(0=失敗), status(1=有欄位沒填 / 2=有欄位格式不正確 / 3=token錯誤 / 4=縣市跟櫃點對不起來 / 5=查無該名登記者或已兌換 / 6=簡訊間隔時間未到)
output : remain_time(status=3時才有, 還剩多少時間才能再發簡訊)
-------------------------------------------------------------------------
output : OK(1=成功)
=========================================================================
func=StoreGetInfo
櫃點兌換查詢
input : sn (透過網址取得)
output : OK(0=失敗), status(1=sn沒有值 / 2=兌換碼比對錯誤,查無此人 / 3=己兌換 / 4=己過兌換時間 / 5=之前己兌換, 無法再兌換)
-------------------------------------------------------------------------
output : OK(1=成功),
output : info(array, [OK=0 && (status=3 || 4)] || OK=1) 包含 ==> city(縣市名稱), store(櫃點名稱), name(登記者姓名), mobile(登記者手機), expire_date(兌換失效時間)
=========================================================================
func=StoreSubmit
櫃點兌換確認兌換
input : sn (透過網址取得)
output : OK(0=失敗), status(1=sn沒有值 / 2=兌換碼比對錯誤,查無此人 / 3=己兌換 / 4=己過兌換時間 / 5=之前己兌換, 無法再兌換)
-------------------------------------------------------------------------
output : OK(1=成功)
*/

//PHP錯誤顯示設定
ini_set("display_errors", "On"); // 顯示錯誤是否打開( On=開, Off=關 )
error_reporting(E_ALL & ~E_NOTICE);

ini_set('memory_limit', '-1');

session_start();

header("Content-Type:text/html; charset=utf-8");

define("CONFIG_DIR",dirname(__FILE__).'/../');
include_once CONFIG_DIR.'db.inc.php';
include_once CONFIG_DIR.'class/common.class.php';
include_once CONFIG_DIR.'class/trigger.class.php';
include_once CONFIG_DIR.'class/mms.class.php';

//設定時區 並 取得目前時間
date_default_timezone_set("Asia/Taipei");
$nowdate = date('Y/m/d H:i:s');

//class init
$common=new Common();

//variable init
$numberFormat="/[0-9]{1,10}/";
$mobileFormat="/[0-9]{10}/";
$json=null;

//定義這是那個活動
//【百優＋怡麗絲爾】=1
//【東京櫃周年慶】=2
//【心機彩粧(MQ)】=3
//【驅黑淨白】=4
//【驅黑Haku】=5
//【MQ.2018.03】=6
//【Haku.2018.03】=7
//【百優.2018.06】=8
$event_no=8;

//兌換頁活動網址
$exchange_url="https://www.beauty-event.com.tw/QQtest/exchange.html";

$city_area = array(
	 '基隆市'=> array('no'=>1, '仁愛區'=> '200', '信義區'=> '201', '中正區'=> '202', '中山區'=> '203', '安樂區'=> '204', '暖暖區'=> '205', '七堵區'=> '206'),
	 '臺北市'=> array('no'=>2, '中正區'=> '100', '大同區'=> '103', '中山區'=> '104', '松山區'=> '105', '大安區'=> '106', '萬華區'=> '108', '信義區'=> '110', '士林區'=> '111', '北投區'=> '112', '內湖區'=> '114', '南港區'=> '115', '文山區'=> '116'),
	 '新北市'=> array('no'=>3,
		'萬里區'=> '207', '金山區'=> '208', '板橋區'=> '220', '汐止區'=> '221', '深坑區'=> '222', '石碇區'=> '223',
		'瑞芳區'=> '224', '平溪區'=> '226', '雙溪區'=> '227', '貢寮區'=> '228', '新店區'=> '231', '坪林區'=> '232',
		'烏來區'=> '233', '永和區'=> '234', '中和區'=> '235', '土城區'=> '236', '三峽區'=> '237', '樹林區'=> '238',
		'鶯歌區'=> '239', '三重區'=> '241', '新莊區'=> '242', '泰山區'=> '243', '林口區'=> '244', '蘆洲區'=> '247',
		'五股區'=> '248', '八里區'=> '249', '淡水區'=> '251', '三芝區'=> '252', '石門區'=> '253'
	 ),
	 '宜蘭縣'=> array(
		'宜蘭市'=> '260', '頭城鎮'=> '261', '礁溪鄉'=> '262', '壯圍鄉'=> '263', '員山鄉'=> '264', '羅東鎮'=> '265',
		'三星鄉'=> '266', '大同鄉'=> '267', '五結鄉'=> '268', '冬山鄉'=> '269', '蘇澳鎮'=> '270', '南澳鄉'=> '272',
		'釣魚臺列嶼'=> '290'
	 ),
	 '新竹市'=> array('no'=>5, '東區'=> '300', '北區'=> '300', '香山區'=> '300'),
	 '新竹縣'=> array('no'=>6,
		'竹北市'=> '302', '湖口鄉'=> '303', '新豐鄉'=> '304', '新埔鎮'=> '305', '關西鎮'=> '306', '芎林鄉'=> '307',
		'寶山鄉'=> '308', '竹東鎮'=> '310', '五峰鄉'=> '311', '橫山鄉'=> '312', '尖石鄉'=> '313', '北埔鄉'=> '314',
		'峨眉鄉'=> '315'
	 ),
	 '桃園市'=> array('no'=>4,
		'中壢區'=> '320', '平鎮區'=> '324', '龍潭區'=> '325', '楊梅區'=> '326', '新屋區'=> '327', '觀音區'=> '328',
		'桃園區'=> '330', '龜山區'=> '333', '八德區'=> '334', '大溪區'=> '335', '復興區'=> '336', '大園區'=> '337',
		'蘆竹區'=> '338'
	 ),
	 '苗栗縣'=> array('no'=>7,
		'竹南鎮'=> '350', '頭份市'=> '351', '三灣鄉'=> '352', '南庄鄉'=> '353', '獅潭鄉'=> '354', '後龍鎮'=> '356',
		'通霄鎮'=> '357', '苑裡鎮'=> '358', '苗栗市'=> '360', '造橋鄉'=> '361', '頭屋鄉'=> '362', '公館鄉'=> '363',
		'大湖鄉'=> '364', '泰安鄉'=> '365', '銅鑼鄉'=> '366', '三義鄉'=> '367', '西湖鄉'=> '368', '卓蘭鎮'=> '369'
	 ),
	 '臺中市'=> array('no'=>8,
		'中區'=> '400', '東區'=> '401', '南區'=> '402', '西區'=> '403', '北區'=> '404', '北屯區'=> '406', '西屯區'=> '407', '南屯區'=> '408',
		'太平區'=> '411', '大里區'=> '412', '霧峰區'=> '413', '烏日區'=> '414', '豐原區'=> '420', '后里區'=> '421',
		'石岡區'=> '422', '東勢區'=> '423', '和平區'=> '424', '新社區'=> '426', '潭子區'=> '427', '大雅區'=> '428',
		'神岡區'=> '429', '大災rray(區'=> '432', '沙鹿區'=> '433', '龍井區'=> '434', '梧棲區'=> '435', '清水區'=> '436',
		'大甲區'=> '437', '外埔區'=> '438', '大安區'=> '439'
	 ),
	 '彰化縣'=> array('no'=>9,
		'彰化市'=> '500', '芬園鄉'=> '502', '花壇鄉'=> '503', '秀水鄉'=> '504', '鹿港鎮'=> '505', '福興鄉'=> '506',
		'線西鄉'=> '507', '和美鎮'=> '508', '伸港鄉'=> '509', '員林市'=> '510', '社頭鄉'=> '511', '永靖鄉'=> '512',
		'埔心鄉'=> '513', '溪湖鎮'=> '514', '大村鄉'=> '515', '埔鹽鄉'=> '516', '田中鎮'=> '520', '北斗鎮'=> '521',
		'田尾鄉'=> '522', '埤頭鄉'=> '523', '溪地rray(鄉'=> '524', '竹塘鄉'=> '525', '二林鎮'=> '526', '大城鄉'=> '527',
		'芳苑鄉'=> '528', '二水鄉'=> '530'
	 ),
	 '南投縣'=> array(
		'南投市'=> '540', '中寮鄉'=> '541', '草屯鎮'=> '542', '國姓鄉'=> '544', '埔里鎮'=> '545', '仁愛鄉'=> '546',
		'名間鄉'=> '551', '集集鎮'=> '552', '水里鄉'=> '553', '魚池鄉'=> '555', '信義鄉'=> '556', '竹山鎮'=> '557',
		'鹿谷鄉'=> '558'
	 ),
	 '嘉義市'=> array('東區'=> '600', '西區'=> '600'),
	 '嘉義縣'=> array(
		'番路鄉'=> '602', '梅山鄉'=> '603', '竹崎鄉'=> '604', '阿里山'=> '605', '中埔鄉'=> '606', '大埔鄉'=> '607',
		'水上鄉'=> '608', '鹿草鄉'=> '611', '太保市'=> '612', '朴子市'=> '613', '東石鄉'=> '614', '六�)鄉'=> '615',
		'新港鄉'=> '616', '民雄鄉'=> '621', '大林鎮'=> '622', '溪口鄉'=> '623', '義竹鄉'=> '624', '布袋鎮'=> '625'
	 ),
	 '雲林縣'=> array(
		'斗南鎮'=> '630', '大埤鄉'=> '631', '虎尾鎮'=> '632', '土庫鎮'=> '633', '褒忠鄉'=> '634', '東勢鄉'=> '635',
		'臺西鄉'=> '636', '崙背鄉'=> '637', '麥寮鄉'=> '638', '斗六市'=> '640', '林內鄉'=> '643', '古坑鄉'=> '646',
		'莿桐鄉'=> '647', '西螺鎮'=> '648', '二崙鄉'=> '649', '北港鎮'=> '651', '水林鄉'=> '652', '口湖鄉'=> '653',
		'四湖鄉'=> '654', '元長鄉'=> '655'
	 ),
	 '臺南市'=> array(
		'中西區'=> '700', '東區'=> '701', '南區'=> '702', '北區'=> '704', '安平區'=> '708', '安南區'=> '709',
		'永康區'=> '710', '歸仁區'=> '711', '新化區'=> '712', '左鎮區'=> '713', '玉井區'=> '714', '楠西區'=> '715',
		'南化區'=> '716', '仁德區'=> '717', '關廟區'=> '718', '龍崎區'=> '719', '官田區'=> '720', '麻豆區'=> '721',
		'佳里區'=> '722', '西港區'=> '723', '七股區'=> '724', '將軍區'=> '725', '學甲區'=> '726', '北門區'=> '727',
		'新營區'=> '730', '後壁區'=> '731', '白河區'=> '732', '東山區'=> '733', '六甲區'=> '734', '下營區'=> '735',
		'柳營區'=> '736', '鹽水區'=> '737', '善化區'=> '741', '大內區'=> '742', '山上區'=> '743', '新市區'=> '744',
		'安定區'=> '745'
	 ),
	 '高雄市'=> array(
		'新興區'=> '800', '前金區'=> '801', '苓雅區'=> '802', '鹽埕區'=> '803', '鼓山區'=> '804', '旗津區'=> '805',
		'前鎮區'=> '806', '三民區'=> '807', '楠梓區'=> '811', '小港區'=> '812', '左營區'=> '813',
		'仁武區'=> '814', '大社區'=> '815', '東沙群島'=> '817', '南沙群島'=> '819', '岡山區'=> '820', '路竹區'=> '821',
		'阿蓮區'=> '822', '田寮區'=> '823',
		'燕巢區'=> '824', '橋頭區'=> '825', '梓官區'=> '826', '彌陀區'=> '827', '永安區'=> '828', '湖內區'=> '829',
		'鳳山區'=> '830', '大寮區'=> '831', '林園區'=> '832', '鳥松區'=> '833', '大樹區'=> '840', '旗山區'=> '842',
		'美濃區'=> '843', '六龜區'=> '844', '內門區'=> '845', '杉林區'=> '846', '甲仙區'=> '847', '桃源區'=> '848',
		'那瑪夏區'=> '849', '茂林區'=> '851', '茄萣區'=> '852'
	 ),
	 '屏東縣'=> array(
		'屏東市'=> '900', '三地門鄉'=> '901', '霧臺鄉'=> '902', '瑪家鄉'=> '903', '九如鄉'=> '904', '里港鄉'=> '905',
		'高樹鄉'=> '906', '鹽埔鄉'=> '907', '長治鄉'=> '908', '麟洛鄉'=> '909', '竹田鄉'=> '911', '內埔鄉'=> '912',
		'萬丹鄉'=> '913', '潮地鎮'=> '920', '泰武鄉'=> '921', '來義鄉'=> '922', '萬巒鄉'=> '923', '崁頂鄉'=> '924',
		'新埤鄉'=> '925', '南地鄉'=> '926', '林邊鄉'=> '927', '東港鎮'=> '928', '琉球鄉'=> '929', '佳冬鄉'=> '931',
		'新園鄉'=> '932', '枋寮鄉'=> '940', '枋山鄉'=> '941', '春日鄉'=> '942', '獅子鄉'=> '943', '車城鄉'=> '944',
		'牡丹鄉'=> '945', '恆春鎮'=> '946', '滿地rray(鄉'=> '947'
	 ),
	 '臺東縣'=> array(
		'臺東市'=> '950', '綠島鄉'=> '951', '蘭嶼鄉'=> '952', '延平鄉'=> '953', '卑南鄉'=> '954', '鹿野鄉'=> '955',
		'關山鎮'=> '956', '海端鄉'=> '957', '池上鄉'=> '958', '東河鄉'=> '959', '成功鎮'=> '961', '長濱鄉'=> '962',
		'太麻里鄉'=> '963', '金峰鄉'=> '964', '大武鄉'=> '965', '達仁鄉'=> '966'
	 ),
	 '花蓮縣'=> array(
		'花蓮市'=> '970', '新城鄉'=> '971', '秀林鄉'=> '972', '吉安鄉'=> '973', '壽豐鄉'=> '974', '鳳林鎮'=> '975',
		'光復鄉'=> '976', '豐濱鄉'=> '977', '瑞穗鄉'=> '978', '萬榮鄉'=> '979', '玉里鎮'=> '981', '卓溪鄉'=> '982',
		'富里鄉'=> '983'
	 ),
	 '金門縣'=> array('金沙鎮'=> '890', '金湖鎮'=> '891', '金寧鄉'=> '892', '金城鎮'=> '893', '烈嶼鄉'=> '894', '烏坵鄉'=> '896'),
	 '連江縣'=> array('南竿鄉'=> '209', '北竿鄉'=> '210', '莒光鄉'=> '211', '東引鄉'=> '212'),
	 '澎湖縣'=> array('馬公市'=> '880', '西嶼鄉'=> '881', '望安鄉'=> '882', '七美鄉'=> '883', '白沙鄉'=> '884', '湖西鄉'=> '885')
);


//if (strcmp($_SERVER['HTTP_HOST'],"www.beauty-event.com.tw")==0 || strcmp($_SERVER['HTTP_HOST'],"beauty-event.com.tw")==0) {	//只接受本機傳送資料
	if (isset($_POST['func'])) {
		$func=trim($_POST['func']);

		$db=new Database();

		if ($json==null) {
			switch ($func) {
				case "CityStoreListByEvent":


					$sqlStr='SELECT s_no, s_c_no, s_code, s_name, s_area, s_addr, s_lnl, s_tel, s_req_total, (s_req_total-s_req_apply) AS s_req_avail FROM Store WHERE s_e_no=:event_no AND s_req_total>0 AND (s_req_total-s_req_apply)>0 AND s_show=1 order by s_c_no, s_name ASC';
					$db->query($sqlStr);
					$db->bind(':event_no', $event_no, PDO::PARAM_INT);
					$storeRows = $db->resultset();

					//把沒有的縣市鄉鎮去掉
					foreach($city_area as $key=>$value){
						foreach($value as $k=>$v){
							$haveZip=0;
							foreach($storeRows as $row){
								if ($v==$row['s_c_no'] && $k==$row['s_area']) {
									$haveZip=1;
									break 1;
								}
							}
							if ($haveZip==0) {
								unset($city_area[$key][$k]);
							}
						}
					}
					$json=array('OK'=>1,'cityRows'=>$city_area,'storeRows'=>$storeRows);
					break;
				case "SubmitForm":
					if (isset($_POST['t_c_no']) && isset($_POST['t_s_no']) && isset($_POST['t_name']) && isset($_POST['t_mobile']) && isset($_POST['t_email'])) {
						$arrInputFields=array('t_c_no'=>array('int'), 't_s_no'=>array('int'), 't_name'=>array('string'), 't_mobile'=>array('string',$mobileFormat), 't_email'=>array('string'));
						$t_c_no=$common->replaceParameter($_POST['t_c_no']);
						$t_s_no=$common->replaceParameter($_POST['t_s_no']);
						$t_name=$common->replaceParameter($_POST['t_name']);
						$t_mobile=$common->replaceParameter($_POST['t_mobile']);
						$t_email=$common->replaceParameter($_POST['t_email']);

						$return_OK=1;
						$return_status=1;
						$return_fields=array();

						//檢查是否有欄位未填
						foreach ($arrInputFields as $field => $value) {
							if (isEmpty($$field)) {
								$return_OK=0;
								$return_status=1;
								array_push($return_fields,$field);
							}
						}

						//檢查欄位格式
						if ($return_OK==1) {
							foreach ($arrInputFields as $field => $value) {
								if (!checkFormatFit($$field, $value[0], $value[1])) {
									$return_OK=0;
									$return_status=2;
									array_push($return_fields,$field);
								}
							}
						}

						//檢查資料庫 ==> City & Store
						if ($return_OK==1) {
							$sqlStr="SELECT COUNT(s_no) AS haveStore,  (s_req_total-s_req_apply) AS reqAvail FROM Store WHERE s_c_no=:c_no AND s_no=:s_no AND s_e_no=:event_no";
							$db->query($sqlStr);
							$db->bind(':c_no', $t_c_no, PDO::PARAM_INT);
							$db->bind(':s_no', $t_s_no, PDO::PARAM_INT);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);

							$rows = $db->resultset();
							if ((int)$rows[0]['haveStore']==0) {
								$return_OK=0;
								$return_status=3;
							} else if ((int)$rows[0]['haveStore']==1) {	//有櫃點
								if ((int)$rows[0]['reqAvail']==0) {	//己額滿
									$return_OK=0;
									$return_status=4;
								}
							} else {
								$return_OK=0;
								$return_status=9;
							}
						}

						//檢查資料庫 ==> 黑名單
						if ($return_OK==1) {
							$sqlStr="SELECT COUNT(b_no) AS haveBlocked FROM Blocked WHERE b_mobile=:t_mobile";
							$db->query($sqlStr);
							$db->bind(':t_mobile', $t_mobile);

							$rows = $db->resultset();
							if ((int)$rows[0]['haveBlocked']>0) {	//這支手機己在黑名單中
								$return_OK=0;
								$return_status=7;
							}
						}

						if ($return_OK==1) {	//檢查是不是己經有兌換過(該event所有記錄)
							$sqlStr="SELECT t_no FROM Tester WHERE t_mobile=:t_mobile AND t_e_no=:event_no AND t_is_exchange=1";
							$db->query($sqlStr);
							$db->bind(':t_mobile', $t_mobile, PDO::PARAM_INT);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);
							$rows = $db->resultset();

							if (count($rows)>0) {		//己經有兌換過 ==> 把其他還沒換的全部改成不能再兌換
								$return_status=6;
								$return_OK=0;
							}
						}

						//檢查資料庫 ==> Tester ==> Mobile Duplicate
						if ($return_OK==1) {
							//檢查單一登記者 (Tester) 是否己過領取期限, 如果己經過期, 就可以設定為己不可領取 (也有可能未登記, 或早己不能領取, 那就沒差)
							fixExpireSingleTester($t_mobile, $event_no, $t_s_no);

							$sqlStr="SELECT t_no, t_is_exchange, t_is_valid, DATE_ADD(t_apply_date, INTERVAL '.EXCHANGE_EXPIRE_DATE.' DAY) as t_expire_date FROM Tester WHERE t_mobile=:t_mobile AND t_e_no=:event_no ORDER BY t_no DESC";
							$db->query($sqlStr);
							$db->bind(':t_mobile', $t_mobile, PDO::PARAM_INT);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);

							$rows = $db->resultset();
							if (count($rows)>0) {	//己有此登記者
								$return_OK=0;
								if ((int)$rows[0]['t_is_exchange']==0 && (int)$rows[0]['t_is_valid']==1) {	//未領取 且 未過領取期限
									$return_status=5;
								}
								if ((int)$rows[0]['t_is_exchange']==1) {	//己兌換
									$return_status=6;
								}
								if ((int)$rows[0]['t_is_exchange']==0 && (int)$rows[0]['t_is_valid']==0) {	//未領取 且 己過期 ==> 可以再次登記
									$return_OK=1;
									$return_status=2;
								}
							}
						}

						//檢查資料庫 ==> Tester ==> Email Duplicate
						if ($return_OK==1) {
							$sqlStr="SELECT t_no, t_is_exchange, t_is_valid, DATE_ADD(t_apply_date, INTERVAL '.EXCHANGE_EXPIRE_DATE.' DAY) as t_expire_date FROM Tester WHERE t_email=:t_email AND t_e_no=:event_no ORDER BY t_no DESC";
							$db->query($sqlStr);
							$db->bind(':t_email', $t_email);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);
							$rows = $db->resultset();
							if (count($rows)>0) {	//己有此登記者

								//安全起見, 再查一次看是不是己兌換過(用email查)
								$sqlStr='SELECT t_no FROM Tester WHERE t_is_exchange=1 AND t_e_no=:event_no AND t_email=:t_email';
								$db->query($sqlStr);
								$db->bind(':t_email', $t_email);
								$db->bind(':event_no', $event_no, PDO::PARAM_INT);
								$rows = $db->resultset();
								if (count($rows)>0) {	//己兌換過
									$return_OK=0;
									$return_status=6;
								}
								//檢查是否從未領取過
								//if ((int)$rows[0]['t_is_exchange']==0 && (int)$rows[0]['t_is_valid']==1) {	//未領取 且 未過領取期限
									//$return_status=8;
								//}

							}
						}

						if ($return_OK==0) {
							if ($return_status==1 || $return_status==2) {
								$json=array('OK'=>'0', 'status'=>$return_status, 'fields'=>$return_fields);
							} else {
								$json=array('OK'=>'0', 'status'=>$return_status);
							}
						} else {	//可以登記
							//新增登登記者
							$sqlStr="INSERT INTO Tester(t_e_no, t_c_no, t_s_no, t_name, t_mobile, t_email, t_apply_ip) VALUES(:event_no, :c_no, :s_no, :t_name, :t_mobile, :t_email, :t_apply_ip)";
							$db->query($sqlStr);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);
							$db->bind(':c_no', $t_c_no, PDO::PARAM_INT);
							$db->bind(':s_no', $t_s_no, PDO::PARAM_INT);
							$db->bind(':t_name', $t_name);
							$db->bind(':t_mobile', $t_mobile, PDO::PARAM_INT);
							$db->bind(':t_email', $t_email);
							$db->bind(':t_apply_ip', getIP());

							$db->execute();
							$t_no=$db->lastInsertId();

							//產生登記代碼 & 長網址 & 短網址 及 簡訊內容
							$t_sn_no=generateSN($t_no);
							$t_l_url=$exchange_url.'?sn='.$t_sn_no;
							$t_s_url=generateShortUrl($t_l_url);
							$t_mms_text=Mms::GetMmsText($t_no, $t_sn_no, $t_s_url);

							//更新登記者資料
							$sqlStr="UPDATE Tester SET t_sn_no=:t_sn_no, t_l_url=:t_l_url, t_s_url=:t_s_url, t_mms_text=:t_mms_text, t_mms_by_tester=1 WHERE t_no=:t_no";
							$db->query($sqlStr);
							$db->bind(':t_sn_no', $t_sn_no);
							$db->bind(':t_l_url', $t_l_url);
							$db->bind(':t_s_url', $t_s_url);
							$db->bind(':t_mms_text', $t_mms_text);
							$db->bind(':t_no', $t_no, PDO::PARAM_INT);
							$db->execute();

							//發送簡訊
							Mms::Sent($t_no,0);

							//Trigger
							Trigger::TesterApply('AfterInsert', $event_no, $t_s_no, 0);

							//產生 token, 並把 token 記到 Session
							$accesstoken=generateToken();	//access_token
							$_SESSION['token']=$accesstoken;

							$json=array('OK'=>'1', 'status'=>$return_status, 't_no'=>$t_no, 't_sn_no'=>$t_sn_no, 'token'=>$accesstoken);
						}
					}
					break;
				case "SentMms":
					if (isset($_POST['t_c_no']) && isset($_POST['t_s_no']) && isset($_POST['t_no']) && isset($_POST['token'])) {
						$arrInputFields=array('t_c_no'=>array('int'), 't_s_no'=>array('int'), 't_no'=>array('int'), 'token'=>array('string'));
						$t_c_no=$common->replaceParameter($_POST['t_c_no']);
						$t_s_no=$common->replaceParameter($_POST['t_s_no']);
						$t_no=$common->replaceParameter($_POST['t_no']);
						$token=$common->replaceParameter($_POST['token']);

						$return_OK=1;
						$return_status=0;
						$return_remain_time=0;

						//檢查是否有欄位未填
						foreach ($arrInputFields as $field => $value) {
							if (isEmpty($$field)) {
								$return_OK=0;
								$return_status=1;
								array_push($return_fields,$field);
							}
						}

						//檢查欄位格式
						if ($return_OK==1) {
							foreach ($arrInputFields as $field => $value) {
								if (!checkFormatFit($$field, $value[0], $value[1])) {
									$return_OK=0;
									$return_status=2;
									array_push($return_fields,$field);
								}
							}
						}

						//檢查 token
						if ($return_OK==1) {
							if (strcmp($token, $_SESSION['token'])<>0) {
								$return_OK=0;
								$return_status=3;
							} else {
								$_SESSION['token']=$token;	//怕 session 過期, 再覆寫一次
							}
						}

						//檢查資料庫 ==> City & Store
						if ($return_OK==1) {
							$sqlStr="SELECT COUNT(s_no) AS total FROM Store WHERE s_c_no=:c_no AND s_no=:s_no AND s_e_no=:event_no";
							$db->query($sqlStr);
							$db->bind(':c_no', $t_c_no, PDO::PARAM_INT);
							$db->bind(':s_no', $t_s_no, PDO::PARAM_INT);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);

							$rows = $db->resultset();
							if ((int)$rows[0]['total']==0) {
								$return_OK=0;
								$return_status=4;
							}
						}

						//檢查資料庫 ==> Tester
						if ($return_OK==1) {
							$sqlStr="SELECT COUNT(t_no) AS total FROM Tester WHERE t_e_no=:event_no AND t_c_no=:c_no AND t_s_no=:s_no AND t_no=:t_no AND t_is_exchange=0 AND t_is_valid=1";
							$db->query($sqlStr);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);
							$db->bind(':c_no', $t_c_no, PDO::PARAM_INT);
							$db->bind(':s_no', $t_s_no, PDO::PARAM_INT);
							$db->bind(':t_no', $t_no, PDO::PARAM_INT);

							$rows = $db->resultset();
							if ((int)$rows[0]['total']==0) {
								$return_OK=0;
								$return_status=5;
							}
						}

						//檢查距離上次發送是不是已經超過 MMS_INTERVAL_TIME 分鐘
						if ($return_OK==1) {
							$sqlStr="SELECT m_c_date FROM Mms WHERE m_t_no=:t_no ORDER BY m_no DESC LIMIT 1";
							$db->query($sqlStr);
							$db->bind(':t_no', $t_no, PDO::PARAM_INT);

							$rows = $db->resultset();
							$olddate=$rows[0]['m_c_date'];

							if (count($rows)==1) {
								$min=(strtotime($nowdate) - strtotime($olddate))/ 60;  //計算相差幾分鐘
								if ($min < MMS_INTERVAL_TIME) {	//還不到可以再發放的時間
									$return_OK=0;
									$return_status=6;
									$return_remain_time=round((MMS_INTERVAL_TIME-$min)*10)/10;
								}
							}
						}

						if ($return_OK==0) {
							if ($return_status==6) {
								$json=array('OK'=>'0', 'status'=>$return_status, 'remain_time'=>$return_remain_time);
							} else {
								$json=array('OK'=>'0', 'status'=>$return_status);
							}
						} else {
							//發送檢訊
							Mms::Sent($t_no,0);

							//更新發送次數
							$sqlStr="UPDATE Tester SET t_mms_by_tester=t_mms_by_tester+1 WHERE t_no=:t_no";
							$db->query($sqlStr);
							$db->bind(':t_no', $t_no, PDO::PARAM_INT);
							$db->execute();

							$json=array('OK'=>'1');
						}
					}
					break;
				case "StoreGetInfo":
					if (isset($_POST['sn'])) {
						$sn_no=$common->replaceParameter($_POST['sn']);

						$return_OK=1;
						$return_status=0;
						$arrInfo=array('name'=>'', 'mobile'=>'', 'email'=>'', 'store'=>'', 'address'=>'', 'expire_date'=>'');

						if (strlen($sn_no)==0) {
							$return_OK=0;
							$return_status=1;
						}

						//比對兌換碼, 確認兌換狀態
						if ($return_OK==1) {
							$sqlStr='SELECT s_name, t_no, t_name, t_mobile, t_email, s_addr, t_is_exchange, t_is_valid, DATE_ADD(t_apply_date, INTERVAL '.EXCHANGE_EXPIRE_DATE.' DAY) as t_expire_date FROM Tester, Store WHERE s_no=t_s_no AND t_e_no=:event_no AND t_sn_no=:sn_no';

							$db->query($sqlStr);
							$db->bind(':sn_no', $sn_no);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);

							$rows = $db->resultset();

							if (count($rows)==0) {	//找無此人
								$return_OK=0;
								$return_status=2;
							} else {
								$arrInfo['name']=$rows[0]['t_name'];
								$arrInfo['mobile']=$rows[0]['t_mobile'];
								$arrInfo['email']=$rows[0]['t_email'];
								$arrInfo['address']=$rows[0]['s_addr'];
								$arrInfo['store']=$rows[0]['s_name'];
								$arrInfo['expire_date']=$rows[0]['t_expire_date'];

								if ((int)$rows[0]['t_is_exchange']==1) {	//己兌換
									$return_OK=0;
									$return_status=3;
								}  else if (strlen(trim($rows[0]['t_email']))==0) {
									$return_OK=0;
									$return_status=6;
								} else if ((strtotime($nowdate) - strtotime($rows[0]['t_expire_date']))>0) {	//己過兌換期間
									if ((int)$rows[0]['t_is_valid']==1) {	//實際上己過期, 但登記者還處於未過期狀態 ==> fixed it
										fixExpireSingleTester($rows[0]['t_mobile'], $event_no, $rows[0]['s_no']);
									}
									$return_OK=0;
									$return_status=4;
								}
							}


							//安全起見, 再查一次看是不是己兌換過(用手機號碼查)
							if (strlen(trim($arrInfo['mobile']))>0) {
								$sqlStr='SELECT t_no FROM Tester WHERE t_is_exchange=1 AND t_e_no=:event_no AND t_mobile=:t_mobile';
								$db->query($sqlStr);
								$db->bind(':t_mobile', $arrInfo['mobile'], PDO::PARAM_INT);
								$db->bind(':event_no', $event_no, PDO::PARAM_INT);
								$rows = $db->resultset();
								if (count($rows)>0) {	//己兌換過
									$return_OK=0;
									$return_status=5;
								}
							}
						}

						if ($return_OK==0) {
							if ($return_status<=2 || $return_status==5) {
								$json=array('OK'=>'0', 'status'=>$return_status);
							} else {
								$json=array('OK'=>'0', 'status'=>$return_status, 'info'=>$arrInfo);
							}
						} else {
							$json=array('OK'=>'1', 'info'=>$arrInfo);
						}
					}
					break;
				case 'StoreSubmit':
					if (isset($_POST['sn'])) {
						$sn_no=$common->replaceParameter($_POST['sn']);

						$return_OK=1;
						$return_status=0;
						$t_no=0;
						$s_no=0;
						$t_mobile='';

						if (strlen($sn_no)==0) {
							$return_OK=0;
							$return_status=1;
						}

						//比對兌換碼, 確認兌換狀態
						if ($return_OK==1) {
							$sqlStr='SELECT t_no, t_s_no, t_email, t_mobile, t_is_exchange, t_is_valid, DATE_ADD(t_apply_date, INTERVAL '.EXCHANGE_EXPIRE_DATE.' DAY) as t_expire_date FROM Tester WHERE  t_e_no=:event_no AND t_sn_no=:sn_no';

							$db->query($sqlStr);
							$db->bind(':sn_no', $sn_no);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);

							$rows = $db->resultset();

							if (count($rows)==0) {	//找無此人
								$return_OK=0;
								$return_status=2;
							} else {
								$t_no=(int)$rows[0]['t_no'];
								$s_no=(int)$rows[0]['t_s_no'];
								$t_mobile=$rows[0]['t_mobile'];

								if ((int)$rows[0]['t_is_exchange']==1) {	//己兌換
									$return_OK=0;
									$return_status=3;
								}  else if (strlen(trim($rows[0]['t_email']))==0) {
									$return_OK=0;
									$return_status=6;
								} else if ((strtotime($nowdate) - strtotime($rows[0]['t_expire_date']))>0) {	//己過兌換期間
									if ((int)$rows[0]['t_is_valid']==1) {	//實際上己過期, 但登記者還處於未過期狀態 ==> fixed it
										fixExpireSingleTester($rows[0]['t_mobile'], $event_no, $rows[0]['s_no']);
									}
									$return_OK=0;
									$return_status=4;
								}
							}

							//安全起見, 再查一次看是不是己兌換過(用手機號碼查)
							if (strlen(trim($t_mobile))>0) {
								$sqlStr='SELECT t_no FROM Tester WHERE t_is_exchange=1 AND t_e_no=:event_no AND t_mobile=:t_mobile';
								$db->query($sqlStr);
								$db->bind(':t_mobile', $t_mobile, PDO::PARAM_INT);
								$db->bind(':event_no', $event_no, PDO::PARAM_INT);
								$rows = $db->resultset();
								if (count($rows)>0) {	//己兌換過
									$return_OK=0;
									$return_status=5;
								}
							}
						}

						if ($return_OK==0) {
							$json=array('OK'=>'0', 'status'=>$return_status);
						} else {
							//更新兌換狀態
							$sqlStr="UPDATE Tester SET t_is_exchange=1, t_exchange_ip=:t_exchange_ip, t_exchange_date=now() WHERE t_no=:t_no AND t_e_no=:event_no AND t_sn_no=:sn_no";
							$db->query($sqlStr);
							$db->bind(':t_exchange_ip', getIP());
							$db->bind(':t_no', $t_no, PDO::PARAM_INT);
							$db->bind(':event_no', $event_no, PDO::PARAM_INT);
							$db->bind(':sn_no', $sn_no);
							$db->execute();

							//trigger
							Trigger::TesterExchangeAfterUpdate($event_no, $s_no, 0, 1, 0);

							$json=array('OK'=>'1');
						}
					}
					break;
			}
		}
		echo json_encode($json);
	}
//}


function getIP() {
  foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
    if (array_key_exists($key, $_SERVER) === true) {
        foreach (explode(',', $_SERVER[$key]) as $ip) {
           if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
              return $ip;
           }
        }
     }
   }
 }

function isEmpty($param) {
	if (strlen($param)==0) {
		return true;
	} else {
		return false;
	}
}

function checkFormatFit($value, $type, $format=null) {
	global $numberFormat;
	$return_status=null;

	switch ($type) {
		case 'int':
			$return_status=preg_match($numberFormat, $value);
			break;
		case 'string':
			if (strlen($format)>0) {
				$return_status=preg_match($format, $value);
			} else {
				$return_status=true;
			}
			break;
	}

	return $return_status;
}

//檢查單一登記者 (Tester) 尚未領取者, 是否己過領取期限, 如果己經過期, 就可以設定為己不可領取 (也有可能未登記, 或早己不能領取, 那就沒差)
//使用時機 ==> 登記者檢查手機號碼是否己登記前
function fixExpireSingleTester($t_mobile,$e_no, $s_no) {
	global $event_no;
	$db=new Database();

	$sqlStr='UPDATE Tester SET t_is_valid=0 WHERE t_apply_date < DATE_SUB(CURRENT_DATE, INTERVAL '.EXCHANGE_EXPIRE_DATE.' DAY) AND t_is_valid=1 AND t_is_exchange=0 AND t_mobile=:t_mobile AND t_e_no=:event_no';
	$db->query($sqlStr);
	$db->bind(':t_mobile', $t_mobile, PDO::PARAM_INT);
	$db->bind(':event_no', $event_no, PDO::PARAM_INT);
	$db->execute();

	$update_count= $db->rowCount();
	if ($update_count>0) {	//有更新
		//檢查這個登記者, 登記的專櫃的 己登記數量 及 己領取數量
		$sqlStr='SELECT s_no, s_req_apply, s_req_exchange, (SELECT COUNT(t_no) FROM Tester WHERE t_e_no=s_e_no AND s_no=t_s_no AND t_is_valid=1) AS actual_req_apply, (SELECT COUNT(t_no) FROM Tester WHERE t_e_no=s_e_no AND s_no=t_s_no AND t_is_exchange=1) AS actual_req_exchange FROM Store WHERE s_no='.$s_no.' AND s_e_no='.$e_no;
		$db->query($sqlStr);
		$rows = $db->resultset();
		foreach($rows as $key=>$value) {
			$updateFields='';
			if ((int)$value['s_req_apply']<>(int)$value['actual_req_apply']) {
				$updateFields=$updateFields.'s_req_apply='.$value['actual_req_apply'];
			}
			if ((int)$value['s_req_exchange']<>(int)$value['actual_req_exchange']) {
				$updateFields=(strlen($updateFields)>0)? $updateFields.',':$updateFields;
				$updateFields=$updateFields.'s_req_exchange='.$value['actual_req_exchange'];
			}
			if (strlen($updateFields)>0) {	//需要更新
				$sqlStr='UPDATE Store SET '.$updateFields.' WHERE s_no='.$value['s_no'];
				$db->query($sqlStr);
				$db->execute();
			}
		}

		//檢查該活動的 可登記總數 及 己登記數量 及 己領取數量
		$sqlStr='SELECT e_no,e_req_total, e_req_apply, e_req_exchange, (SELECT IFNULL(SUM(s_req_total),0) FROM Store WHERE s_e_no=e_no) AS acutal_req_total, (SELECT IFNULL(SUM(s_req_apply),0) FROM Store WHERE s_e_no=e_no) AS acutal_req_apply, (SELECT IFNULL(SUM(s_req_exchange),0) FROM Store WHERE s_e_no=e_no) AS acutal_req_exchange FROM Event WHERE e_no='.$e_no;
		$db->query($sqlStr);
		$rows = $db->resultset();
		foreach($rows as $key=>$value) {
			$updateFields='';
			if ((int)$value['e_req_total']<>(int)$value['acutal_req_total']) {
				$updateFields=$updateFields.'e_req_total='.$value['acutal_req_total'];
			}
			if ((int)$value['e_req_apply']<>(int)$value['acutal_req_apply']) {
				$updateFields=(strlen($updateFields)>0)? $updateFields.',':$updateFields;
				$updateFields=$updateFields.'e_req_apply='.$value['acutal_req_apply'];
			}
			if ((int)$value['e_req_exchange']<>(int)$value['acutal_req_exchange']) {
				$updateFields=(strlen($updateFields)>0)? $updateFields.',':$updateFields;
				$updateFields=$updateFields.'e_req_exchange='.$value['acutal_req_exchange'];
			}
			if (strlen($updateFields)>0) {	//需要更新
				$sqlStr='UPDATE Event SET '.$updateFields.' WHERE e_no='.$value['e_no'];
				$db->query($sqlStr);
				$db->execute();
			}
		}
	}
}

//產生登記代碼
function generateSN($t_no) {
	return $t_no.'_'.substr(md5(rand()),0,SN_STRING_LEN);
}

//產生短網址
function generateShortUrl($long_url) {
	return file_get_contents( 'http://tinyurl.com/api-create.php?url='.urlencode($long_url) );
}

//產生一長串亂數, 作為 check user 是用同一視窗吏用
function generateToken() {
	return substr(md5(rand()),0,32);
}
?>
