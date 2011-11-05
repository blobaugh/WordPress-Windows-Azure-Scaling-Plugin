<form action="" method="post">
    <p>Subscription ID: <input type="text" name="subscription_id" size="50" value="<?php echo $subscription_id; ?>"/> (how to find)</p>
    <p>
        Certificate (open your certifcate file and paste the contents here)
        <br/><textarea name="certificate" cols="80" rows="8"><?php echo $certificate; ?></textarea>
    </p>
    <p>Certificate Thumbprint: <input type="text" name="certificate_thumbprint" size="50" value="<?php echo $certificate_thumbprint; ?>"/> (how to find)</p>
    <p>Deployment Endpoint: <input type="text" name="deployment_endpoint" size="50" value="<?php echo $deployment_endpoint; ?>"/> (how to find)</p>
    <p>Deployment Slot: <input type="text" name="deployment_slot" size="50" value="<?php echo $deployment_slot; ?>"/> Production or Staging</p>
    <p>Deployment Role Name: <input type="text" name="deployment_role_name" size="50" value="<?php echo $deployment_role_name; ?>"/> (how to find)</p>
    <p>Storage Endpoint: <input type="text" name="storage_endpoint" size="50" value="<?php echo $storage_endpoint; ?>"/> (how to find)</p>
    <p>Storage Key: <input type="text" name="storage_key" size="90" value="<?php echo $storage_key; ?>"/> (how to find)</p>
    <p><input type="submit" name="submit" value="Save Settings"/></p>
</form>

<p>(links to articles on creating certificates)</p>
