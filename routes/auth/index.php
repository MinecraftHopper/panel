<?php

use \AE97\Panel\Authentication, \AE97\Panel\Utilities;

$this->respond('GET', '/login/?', function($request, $response, $service, $app) {
    if (Authentication::verifySession($app)) {
        $response->redirect("/", 302);
    }
    $service->render(HTML_DIR . 'index.phtml', array('action' => 'login', 'page' => HTML_DIR . 'auth/login.phtml', 'redirect' => $request->param('redirect')));
});

$this->respond('POST', '/login/?', function($request, $response, $service, $app) {
    $service->validateParam('email', 'Please enter a valid eamail')->isLen(5, 256);
    $service->validateParam('password', 'Please enter a password')->isLen(1, 256);
    $statement = $app->auth_db->prepare("SELECT uuid,password,approved,verified,email FROM users WHERE email=?");
    $statement->execute(array($request->param("email")));
    $statement->setFetchMode(PDO::FETCH_ASSOC);
    $db = $statement->fetch();
    if ((!isset($db['password']) || !isset($db['uuid']) || !isset($db['approved']) || !isset($db['email'])) || !password_verify($request->param('password'), $db['password'])) {

    }
    if (!$db['verified']) {
        throw new Exception("Your email has not been verified");
    } else if (!$db['approved']) {
        throw new Exception("Your account has not been approved");
    } else {
        $str = Utilities::generate_string(64);
        $statement = $app->auth_db->prepare("INSERT INTO session (uuid, sessionToken) VALUES (?, ?) ON DUPLICATE KEY UPDATE sessionToken = ?");
        $statement->execute(array($db['uuid'], $str, $str));
        $_SESSION['uuid'] = $db['uuid'];
        $_SESSION['session'] = $str;
        $response->redirect('/', 302);
    }
});

//Logout
$this->respond('GET', '/logout', function($request, $response, $service, $app) {
    if (!Authentication::verifySession($app)) {
        $response->redirect("/", 302);
    }
    try {
        $statement = $app->auth_db->prepare("DELETE FROM session WHERE uuid = ?");
        $statement->execute(array($_SESSION['uuid']));
    } catch (PDOException $ex) {
        Utilities::logError($ex);
    }
    Authentication::clearSession();
    $service->render(HTML_DIR . 'index.phtml', array('action' => 'logout', 'page' => HTML_DIR . 'auth/logout.phtml'));
    $response->redirect("/", 302);
});

//Register
$this->respond('GET', '/register', function($request, $response, $service, $app) {
    if (!Authentication::verifySession($app)) {
        $service->render(HTML_DIR . 'index.phtml', array('action' => 'register', 'page' => HTML_DIR . 'auth/register.phtml'));
    } else {
        $response->redirect("/", 302);
    }
});

$this->respond('POST', '/register', function($request, $response, $service, $app) {
    $service->addValidator('equal', function($str, $compare) {
        return $str == $compare;
    });
    try {
        $service->validateParam('username', 'Invalid username, must be 5-64 characters')->isLen(3, 64);
        $service->validateParam('email', 'Invalid email')->isLen(5, 256)->isEmail();
        $service->validateParam('email-verify', 'Invalid email')->isLen(5, 256)->isEmail();
        $service->validateParam('password', 'Invalid password, must be 5-64 characters')->isLen(5, 256);
        $service->validateParam('password-verify', 'Invalid password, must be 5-64 characters')->isLen(5, 256);
        $service->validateParam('email', 'Emails do not match')->isEqual($request->param('email-verify'));
        $service->validateParam('password', 'Passwords do not match')->isEqual($request->param('password-verify'));
    } catch (Exception $e) {
        $service->flash('Error: ' . $e->getMessage());
        $response->redirect("/auth/register", 302);
        return;
    }
    $statement = $app->auth_db->prepare("SELECT uuid,username FROM users WHERE email=? OR username=?");
    $statement->execute(array($request->param('email'), $request->param('username')));
    $result = $statement->fetch();
    if (isset($result['uuid'])) {
        $service->flash('Email already exists, please use another');
        return;
    }
    if (isset($result['username'])) {
        $service->flash('Username already exists, please use another');
        return;
    } else {
        $createUserStatement = $app->auth_db->prepare('INSERT INTO users (uuid,username,email,password,verified,approved) values (?,?,?,?,?,?)');
        $hashedPW = password_hash($request->param('password'), PASSWORD_DEFAULT);
        $createUserStatement->execute(array(Utilities::generateGUID(), $request->param('username'), $request->param('email'), $hashedPW, 0, 0));

        $verificationStatement = $app->auth_db->prepare('INSERT INTO verification (email, code) VALUES (?, ?)');
        $approveKey = Utilities::generate_string(32);
        $verificationStatement->execute(array($request->param('email'), $approveKey));

        $app->mail->sendMessage($app->domain, array('from' => 'Noreply <' . $app->email . '>',
            'to' => $request->param('email'),
            'subject' => 'Account verification',
            'html' => 'Someone has registered an account on <a href="' . $app->fullsite . '">' . $app->fullsite . '</a> using this email. '
            . 'If this was you, please click the following link to verify your email: <a href="' . $app->fullsite . '/auth/verify?email=' . $request->param("email") . '&key=' . $approveKey . '">Verify email</a>'));
        $service->flash("Your account has been created, an email has been sent to verify");
        $response->redirect("/auth/login", 302);
    }
});

//Reset
$this->respond('GET', '/resetpw', function($request, $response, $service, $app) {
    if ($request->param('uuid') == null || $request->param('resetkey') == null) {
        $service->render(HTML_DIR . 'index.phtml', array('action' => 'resetpw', 'page' => HTML_DIR . 'auth/resetpw.phtml'));
    } else {
        try {
            $service->validateParam('uuid', 'Invalid uuid');
            $service->validateParam('resetkey', 'Invalid reset key')->isLen(64);
        } catch (Exception $e) {
            $service->flash("Error: " . $e->getMessage());
            $response->redirect('/auth/resetpw', 302);
        }
        $statement = $app->auth_db->prepare("SELECT resetkey FROM passwordreset WHERE uuid=?");
        $statement->execute(array($request->param('uuid')));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetch();
        if (!isset($db['resetkey']) || !isset($db['uuid'])) {
            $service->flash("Error: No reset was requested for this account");
            $response->redirect('/auth/resetpw', 302);
        } else if (!isset($db['verified']) || $db['verified'] == 0) {
            $service->flash("Error: Account not verified");
            $response->redirect('/auth/resetpw', 302);
        } else if ($db['resetkey'] == $request->param('resetkey')) {
            $unhashed = Utilities::generate_string(16);
            $newpass = password_hash($unhashed, PASSWORD_DEFAULT);
            $app->auth_db->prepare("UPDATE users SET password = ? WHERE uuid = ?")->execute(array($newpass, $db['uuid']));
            $app->mail->sendMessage($app->domain, array('from' => 'Noreply@ae97.net <' . $app->email . '>',
                'to' => $db['email'],
                'subject' => 'New panel password', 'html' => 'Your password has been changed. Your new password is : ' . $unhashed));
            $service->flash('Your new password has been emailed to you');
            $response->redirect('/auth/login', 302);
        } else {
            $service->flash("Error: Reset key has expired");
            $response->redirect('/auth/resetpw', 302);
        }
    }
});

$this->respond('POST', '/resetpw', function($request, $response, $service, $app) {
    $service->validateParam('email', 'Invalid email')->isLen(5, 256);
    $statement = $app->auth_db->prepare("SELECT uuid,username,email,verified FROM users WHERE email=?");
    $statement->execute(array($request->param('email')));
    $db = $statement->fetch();
    if (!isset($db['email'])) {
        throw new Exception("No user " . $request->param('email') . " found");
    } else if (!isset($db['verified']) || $db['verified'] == 0) {
        throw new Exception("Account " . $request->param('email') . " not verified");
    } else {
        $uuid = $db['uuid'];
        $resetkey = Utilities::generate_string(64);
        $app->auth_db->prepare("UPDATE passwordreset SET resetkey = ? WHERE uuid = ?")->execute(array($resetkey, $uuid));
        $url = $app->fullsite . '/resetpw?uuid=' . $uuid . '&resetkey=' . $resetkey;
        $app->mail->sendMessage($app->domain, array('from' => 'Noreply <' . $app->email . '>',
            'to' => $db['email'],
            'subject' => 'Password reset for cp.ae97.net',
            'html' => 'Someone requested your password to be reset. If you wanted to do this, please use <strong><a href="' . $url . '">this link</a></strong> to '
            . 'reset your password'));
        $service->flash('Your reset link has been emailed to you');
        $response->redirect('/auth/login', 302);
    }
});

//Verify
$this->respond('GET', '/verify', function($request, $response, $service, $app) {
    $service->validateParam('email', 'Invalid email')->isLen(5, 256)->isEmail();
    $service->validateParam('key', 'Invalid verify key')->isLen(32);
    $statement = $app->auth_db->prepare("SELECT code FROM verification WHERE email=?");
    $statement->execute(array($request->param("email")));
    $db = $statement->fetch();
    if ($request->param('key') == $db['code']) {
        $statement = $app->auth_db->prepare("UPDATE users SET verified = 1 WHERE email=?");
        $statement->execute(array($request->param('email')));
        $service->flash('Your email has been verified');
    } else {
        $service->flash('Error: Invalid verify key for the email');
    }
    $response->redirect("/auth/login", 302);
});
