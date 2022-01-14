<html>

<body>
    <?php session_start();
if (count($_SESSION["admins"]) > 0) : ?>
        <table>
            <tr>
                <td>username</td>
                <td>email</td>
                <td>gravatar</td>
            </tr>
            <?php foreach ($_SESSION["admins"] as $id => $admin) : ?>
		<tr>
                    <td><?php echo $admin["username"]; ?></td>
                    <td><?php echo $admin["email"]; ?></td>
                    <td><?php echo $admin["gravatar"]; ?></td>
                    <td>
                        <div class="btn-group">
                            <a class="btn btn-primary" href="<?php echo "req.php?" . $_SERVER['QUERY_STRING'] . "&user=" . $id ?>">Login</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>

</html>
