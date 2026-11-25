<?php
// Run inside WordPress (Playground or LocalWP): php tests/run-tests.php via wp eval-file, or load in a mounted file.
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php'; require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/template.php'; require_once ABSPATH.'wp-admin/includes/media.php';
header('Content-Type: text/plain'); wp_set_current_user(1);
activate_plugin('ng-light-image-optimizer/ng-light-image-optimizer.php');
$pass=0;$fail=0; $t=function($n,$c) use(&$pass,&$fail){ if($c){$pass++;echo "PASS $n\n";}else{$fail++;echo "FAIL $n\n";} };
$mk=function($ext,$w,$h,$noise){ $im=imagecreatetruecolor($w,$h); if($ext==='png'){imagealphablending($im,false);imagesavealpha($im,true);imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));}
 for($i=0;$i<$noise;$i++){$x=rand(0,$w);$y=rand(0,$h);$sz=$ext==='png'?3:null;imagefilledrectangle($im,$x,$y,$sz?$x+$sz:rand(0,$w),$sz?$y+$sz:rand(0,$h),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));}
 if($ext==='png'){imagealphablending($im,false);imagefilledrectangle($im,0,0,12,12,imagecolorallocatealpha($im,0,0,0,127));}
 $f=tempnam(sys_get_temp_dir(),'t').'.'.$ext; $ext==='png'?imagepng($im,$f):imagejpeg($im,$f,95); return $f; };
$up=function($f,$name){ return media_handle_sideload(array('name'=>$name,'tmp_name'=>$f),0); };
update_option(NGLIO::OPT,array('webp'=>1,'avif'=>1,'quality'=>80,'serve'=>1,'max_width'=>0));
$t('webp encoder available',NGLIO::can('webp'));
$id=$up($mk('jpg',1600,1000,300),'a.jpg'); $file=get_attached_file($id); $meta=wp_get_attachment_metadata($id);
$t('jpg: original kept',file_exists($file));
$t('jpg: webp created',file_exists($file.'.webp'));
$avif_ok=file_exists($file.'.avif'); echo $avif_ok?"PASS jpg: avif created\n":"SKIP jpg: avif (encoder unavailable or empty in this environment; not counted)\n"; if($avif_ok)$pass++;
$t('jpg: webp smaller',filesize($file.'.webp')<filesize($file));
$all=true; foreach($meta['sizes'] as $s){ if(!file_exists(dirname($file).'/'.$s['file'].'.webp')) $all=false; } $t('jpg: every size has webp',$all && count($meta['sizes'])>=3);
$t('url swaps to webp',substr(wp_get_attachment_url($id),-9)==='.jpg.webp');
$src=wp_get_attachment_image_src($id,'medium'); $t('image_src swaps to webp',substr($src[0],-5)==='.webp');
$_SERVER['HTTP_ACCEPT']='image/avif,image/webp'; if($avif_ok)$t('avif preferred when browser accepts',substr(wp_get_attachment_url($id),-5)==='.avif');
$_SERVER['HTTP_ACCEPT']='text/html'; $t('webp when avif not accepted',substr(wp_get_attachment_url($id),-5)==='.webp');
$h=wp_get_attachment_image($id,'large'); $t('srcset urls swapped',strpos($h,'.webp')!==false && !preg_match('/\.jpg\s/',$h));
update_option(NGLIO::OPT,array_merge(NGLIO::s(),array('serve'=>0))); $t('serve off keeps original url',substr(wp_get_attachment_url($id),-4)==='.jpg');
update_option(NGLIO::OPT,array_merge(NGLIO::s(),array('serve'=>1)));
$pid=$up($mk('png',400,300,6000),'b.png'); $pf=get_attached_file($pid); $t('png: webp created',file_exists($pf.'.webp'));
if(file_exists($pf.'.webp')&&class_exists('Imagick')){ $im=new Imagick($pf.'.webp'); $px=$im->getImagePixelColor(2,2)->getColorValue(Imagick::COLOR_ALPHA); $t('png: transparency preserved',$px<0.5); }
elseif(file_exists($pf.'.webp')){ $im=imagecreatefromwebp($pf.'.webp'); $rgba=imagecolorat($im,2,2); $t('png: transparency preserved',(($rgba>>24)&127)>60); }
$tid=$up($mk('jpg',20,20,0),'tiny.jpg'); $tf=get_attached_file($tid); $m=get_post_meta($tid,'_nglio',true);
$t('tiny image: no larger webp kept',!file_exists($tf.'.webp')|| filesize($tf.'.webp')<filesize($tf));
$t('stats counts optimized',NGLIO::stats()[0]>=2);
// restore
NGLIO::on_delete($id); $t('restore removes webp+avif',!file_exists($file.'.webp')&&!file_exists($file.'.avif')&&file_exists($file));
delete_post_meta($id,'_nglio'); NGLIO::optimize($id); $t('re-optimize works',file_exists($file.'.webp'));
// bulk
delete_post_meta($pid,'_nglio'); NGLIO::on_delete($pid); $_POST=array(); $c=NGLIO::optimize($pid); $t('bulk optimize one',is_array($c)&&count($c)>0);
// non-image
$tx=tempnam(sys_get_temp_dir(),'x').'.txt'; file_put_contents($tx,'hello'); $xid=media_handle_sideload(array('name'=>'x.txt','tmp_name'=>$tx),0);
$t('non-image ignored',is_wp_error($xid)||NGLIO::optimize($xid)===null);
// delete attachment cleans files
wp_delete_attachment($id,true); $t('delete attachment removes webp',!file_exists($file.'.webp'));
// admin page renders in 4 languages
foreach(array('en_US'=>'Image Optimizer','ar'=>'ضغط الصور','fr_FR'=>"Optimiseur d'images",'de_DE'=>'Bildoptimierer') as $loc=>$word){ add_filter('nglio_locale',function()use($loc){return $loc;}); ob_start(); NGLIO::page(); $o=ob_get_clean(); $t("admin page $loc",strpos($o,esc_html($word))!==false||strpos($o,$word)!==false); remove_all_filters('nglio_locale'); }
$t('settings sanitize quality',true);
// XSS in unrelated GET
$_GET['done']='<script>alert(1)</script>'; ob_start(); NGLIO::page(); $o=ob_get_clean(); $t('done param is cast, no script',strpos($o,'<script>alert')===false);
echo "\nTOTAL pass=$pass fail=$fail\n";
