<html>
<style>
    .user-container{
        display:flex;
        width:100%;
        height:100%;
        align-items:center;
        justify-content:center;
        flex-direction:column;
    }
    .user-container h1{
        font-size:42px;
        font-weight:900;
        color:#00458b;
    }
    .user-block{
        display:flex;
        border-radius:100px;
        background:white;
        box-shadow: 0 0 19px 0 rgb(50 142 192 / 17%);
        text-decoration:none;
        width:400px;
        margin:20px 0 ;
        transition:all 0.4s ease;
        align-items:center;

    }
    .user-infos{
        padding-left:20px;
    }
    .user-block:hover{
        transform:scale(1.1);
        box-shadow: 5px 5px 19px 0 rgb(50 142 192 / 27%);
    }
    .user-block h3{
        font-family:Roboto, sans-serif;
        font-weight:900;
        color:#00458b;
            margin-bottom: 5px;
    }
    .user-block p{
        font-family:Roboto, sans-serif;
        font-weight:500;
        color:#3FD2C7;
        margin-top: 5px;
    }
    .user-image, .user-fallback{
        border-radius:100%;
        width:60px;
        height:60px;
        border:2px solid #3FD2C7;
        padding:10px;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    .user-fallback{
        background: #00458a;
        color: white;
        font-size: 30px;
        font-weight: 200;
        margin: 8px;
        padding: 10px;
        text-transform: uppercase;
    }
    .user-image img{
        border-radius:100%;
        width:50px;
        height:50px;
        transition:all 0.6s ease;
    }
    .user-block:hover img{
        transform:rotate(360deg);
    }
    body {
    background: #f0f5f6;
    font-family:Roboto, sans-serif;
    }
</style>

<body>
    <?php session_start();
    if (count($_SESSION["admins"]) > 0) : ?>
            <div class="user-container">
                <?php if ($_SESSION["lang"] == "fr_FR") : ?>
                    <h1>Choisissez un compte</h1>
                <?php else : ?>
                    <h1>Select an account</h1>
                <?php endif; ?>
            <?php foreach ($_SESSION["admins"] as $id => $admin) : ?>

                <a class="user-block" href="<?php echo "/?rest_route=/sso/v1/login&x-action=/v1/login" . "&user=" . $id ?>">
                        <?php if($admin["gravatar"]){ ?>
                            <span class="user-image"><?php echo $admin["gravatar"]; ?></span>
                        <?php }else{ ?>
                            <span class="user-fallback"><?php echo substr($admin["username"], 0, 1);?></span>
                        <?php } ?>
                        <span class="user-infos">
                            <h3><?php echo $admin["username"]; ?></h3>
                            <p><?php echo $admin["email"]; ?></p>
                        </span>
                </a>

            <?php endforeach; ?>
            </div>
    <?php endif; ?>
</body>

</html>
