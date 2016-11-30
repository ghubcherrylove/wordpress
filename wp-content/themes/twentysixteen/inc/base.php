<div class="wrap">
    <h2>基本设置</h2>
    <form method="POST" action="">
        <input type="hidden" name="update_options" value="true" />
 
        <table class="form-table">
            <tbody>
                <tr valign="top">
                    <th scope="row"><label for="text-demo">文本框(text)示例</label></th>
                    <td>
                        <input type="input" name="text-demo" id="text-demo" class="regular-text" value="<?php echo get_option('text-demo'); ?>" />
                        <p class="description">说明文字</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="textarea-demo">文本域(textarea)示例</label></th>
                    <td><textarea name="textarea-demo" id="textarea-demo" class="large-text"><?php echo get_option('textarea-demo'); ?></textarea></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label>下拉列表(Select)示例</label></th>
                    <td><select name ="select-demo">
                        <?php $colour = get_option('select-demo'); ?>
                        <option value="gray" <?php if ($colour=='gray') { echo 'selected'; } ?>>灰色</option>
                        <option value="blue" <?php if ($colour=='blue') { echo 'selected'; } ?>>浅蓝</option>
                    </select></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="checkbox-demo">复选框(checkbox)示例</label></th>
                    <td><input type="checkbox" name="checkbox-demo" id="checkbox-demo" <?php echo get_option('checkbox-demo'); ?> /> 任何人都可以注册</td>
                </tr>
            </tbody>
        </table>
        <p><input type="submit" class="button-primary" name="admin_options" value="更新数据"/></p>
    </form>
</div>