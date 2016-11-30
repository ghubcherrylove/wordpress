<?php  /**主题设置项更新页面**/
 
//成功提示
define('SuccessInfo','<div class="updated"><p><strong>设置已保存。</strong></p></div>');
 
//基本设置
function BaseSettings(){
    if ($_POST['update_options']=='true') {
        // 数据更新验证
        update_option('text-demo', $_POST['text-demo']); //input
        update_option('textarea-demo', $_POST['textarea-demo']); //textarea
        update_option('select-demo', $_POST['select-demo']); //select
        if ($_POST['checkbox-demo']=='on') { $display = 'checked'; } else { $display = ''; }
        update_option('checkbox-demo', $display); //checkbox
        echo SuccessInfo;
    }
    require_once(get_template_directory().'/inc/base.php'); //代码解耦
    add_action('admin_menu', 'BaseSettings');
}
 
//高级设置
function AdvancedSettings(){
    if ($_POST['update_options']=='true') {
        update_option('categories-demo', $_POST['categories-demo']); //Categories
        update_option('pages-demo', $_POST['pages-demo']); //Pages
        update_option('upload-demo', $_POST['upload-demo']); //Upload
        echo SuccessInfo;
    }
    require_once(get_template_directory().'/inc/advanced.php');  //代码解耦
    add_action('admin_menu', 'AdvancedSettings');
}
 
?>