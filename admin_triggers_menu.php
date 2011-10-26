<form action="" method="post" id="trigger_form">
<h2>Deployment Stats</h2>
<ul>
        <li>Number of instances: ###/max</li>
        <li>Memory %: ###/max</li>
        <li>CPU %: ###/max</li>
        <li>Connections: ###/max</li>
</ul>

<h2>Manual Control</h2>

<label><input type="radio" name="manual_control" value="1" onclick="document.forms['trigger_form'].submit();;" <?php if($manual_control) echo "CHECKED"; ?>/> On</label> <label><input type="radio" name="manual_control" value="0" onclick="document.forms['trigger_form'].submit();;" <?php if(!$manual_control) echo "CHECKED"; ?>/> Off</label>

<?php
if($manual_control) {
    echo '<div style="display: none;">';
} else {
    echo '<div>';
}
?>


<br/><br/>

<h2>DoSS Protection</h2>
<table cellpadding="5">
        <tr>
                <td>Max allowed instances</td>
                <td><input type="text" name="max_instances" value="<?php echo $max_instances; ?>" /> * Prevents cost overruns</td>
        </tr>
        <tr>
                <td>Min allowed instances</td>
                <td><input type="text" name="min_instances" value="<?php echo $min_instances; ?>"/> * 2 or higher for SLA</td>
        </tr>
</table>


<h2>Triggers</h2>
<table cellpadding="5">
        <tr>
                <td>Memory %</td>
                <td>
                        In: <input type="text" name="memory_min" value="<?php echo $memory_min; ?>"/>
                        <br/>Out: <input type="text" name="memory_max" value="<?php echo $memory_max; ?>"/>
                </td>
        </tr>
        
        <tr>
                <td>CPU %</td>
                <td>
                        In: <input type="text" name="cpu_min" value="<?php echo $cpu_min; ?>"/>
                        <br/>Out: <input type="text" name="cpu_max" value="<?php echo $cpu_max; ?>"/>
                </td>
        </tr>
        
        <tr>
                <td>Connections</td>
                <td>
                        In: <input type="text" name="connections_min" value="<?php echo $connections_min; ?>"/>
                        <br/>Out: <input type="text" name="connections_max" value="<?php echo $connections_max; ?>" />
                </td>
        </tr>
        <tr>
            <td colspan="2"><input type="submit"/></td>
        </tr>
</table>

</div>
</form> 