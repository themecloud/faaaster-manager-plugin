<html>
<style>
    .err-container {
        display: flex;
        width: 100%;
        height: 100%;
        align-items: center;
        justify-content: center;
        flex-direction: column;
    }

    .err-container h1 {
        font-size: 42px;
        font-weight: 900;
        color: #00458b;
    }

    .err-block {
        display: flex;
        border-radius: 100px;
        background: white;
        box-shadow: 0 0 19px 0 rgb(50 142 192 / 17%);
        text-decoration: none;
        width: 400px;
        margin: 20px 0;
        transition: all 0.4s ease;
        align-items: center;

    }

    .err-infos {
        padding-left: 20px;
    }

    .err-block:hover {
        transform: scale(1.1);
        box-shadow: 5px 5px 19px 0 rgb(50 142 192 / 27%);
    }

    .err-block h3 {
        font-family: Roboto, sans-serif;
        font-weight: 900;
        color: #00458b;
        margin-bottom: 5px;
    }

    .err-block p {
        font-family: Roboto, sans-serif;
        font-weight: 500;
        color: #3FD2C7;
        margin-top: 5px;
        font-weight: bold;
    }

    body {
        background: #f0f5f6;
        font-family: Roboto, sans-serif;
    }
</style>

<body>
    <?php session_start(); ?>
    <div class="err-container">
        <h1><?php echo $_GET["error_description"]; ?></h1>


        <?php if ($_SESSION["lang"] == "fr_FR") : ?>
            <p>Assurez-vous que votre compte est autorisé à se connecter à ce site.</p>
        <?php else : ?>
            <p>Make sure your account is allowed to log into this site.</p>
        <?php endif; ?>
    </div>
</body>
</html>
