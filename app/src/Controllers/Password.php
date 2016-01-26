<?php
namespace Membership\Controllers;

use Membership\Controllers;
use Slim\Exception\NotFoundException;

class Password extends Controllers
{
    public function update($request, $response, $args)
    {
        if ($request->isPost()) {
            $validator = $this->validator;
            $salt_pwd = $this->settings['salt_pwd'];

            $validator->createInput($_POST);
            $validator->rule('required', array(
                'oldpassword',
                'password',
                'repassword'
            ));

            $validator->addNewRule('check_oldpassword', function ($field, $value, array $params) use ($db, $salt_pwd) {
                $salted_current_pwd = md5($salt_pwd.$value);

                $q_current_pwd_count = $this->db->createQueryBuilder()
                ->select('COUNT(*) AS total_data')
                ->from('users')
                ->where('user_id = :uid')
                ->andWhere('password = :pwd')
                ->setParameter(':uid', $_SESSION['MembershipAuth']['user_id'])
                ->setParameter(':pwd', $salted_current_pwd)
                ->execute();

                $current_pwd_count = (int) $q_current_pwd_count->fetch()['total_data'];
                if ($current_pwd_count > 0) {
                    return true;
                }

                return false;

            }, 'Wrong! Please remember your old password');

            $validator->addNewRule('check_repassword', function ($field, $value, array $params) {
                if ($value != $_POST['password']) {
                    return false;
                }

                return true;

            }, 'Not match with choosen new password');

            $validator->rule('check_oldpassword', 'oldpassword');
            $validator->rule('check_repassword', 'repassword');

            if ($validator->validate()) {
                $salted_new_pwd = md5($salt_pwd.$_POST['password']);

                $this->db->update('users', array(
                    'password' => $salted_new_pwd,
                    'modified' => date('Y-m-d H:i:s'),
                    'modified_by' => $_SESSION['MembershipAuth']['user_id']
                ), array('user_id' => $_SESSION['MembershipAuth']['user_id']));

                $this->flash->addMessage('success', 'Password anda berhasil diubah! Selamat!');
                return $response->withStatus(302)->withHeader('Location', $this->router->pathFor('membership-profile'));

            } else {
                $this->flash->addMessage('warning', 'Masih ada isian-isian wajib yang belum anda isi. Atau masih ada isian yang belum diisi dengan benar');
            }
        }

        $this->view->addData(
            array(
                'page_title' => 'Membership',
                'sub_page_title' => 'Update Password'
            ),
            'layouts::system'
        );

        return $this->view->render(
            $response,
            'membership/update-password'
        );
    }

    public function forgot($request, $response, $args)
    {
        $gcaptcha_site_key = $this->settings['gcaptcha']['site_key'];
        $gcaptcha_secret = $this->settings['gcaptcha']['secret'];
        $use_captcha = $this->settings['use_captcha'];

        if ($request->isPost()) {
            $db = $this->db;
            $validator = $this->validator;

            $success_msg = 'Email konfirmasi lupa password sudah berhasil dikirim. Segera check email anda. Terimakasih ^_^';
            $success_msg_alt = 'Email konfirmasi lupa password sudah berhasil dikirim. Segera check email anda.<br /><br /><strong>Kemungkinan email akan sampai agak terlambat, karena email server kami sedang mengalami sedikit kendala teknis. Jika belum juga mendapatkan email, maka jangan ragu untuk laporkan kepada kami melalu email: report@phpindonesia.or.id</strong><br /><br />Terimakasih ^_^';

            $validator->createInput($_POST);

            $validator->addNewRule('check_email_exist', function ($field, $value, array $params) use ($db) {
                $q_email_exist = $db->createQueryBuilder()
                    ->select('COUNT(*) AS total_data')
                    ->from('users')
                    ->where('email = :email')
                    ->andWhere('activated = :act')
                    ->andWhere('deleted = :d')
                    ->setParameter(':email', trim($_POST['email']))
                    ->setParameter(':act', 'Y', \Doctrine\DBAL\Types\Type::STRING)
                    ->setParameter(':d', 'N', \Doctrine\DBAL\Types\Type::STRING)
                    ->execute();

                $email_exist = (int) $q_email_exist->fetch()['total_data'];
                if ($email_exist > 0) {
                    return true;
                }

                return false;

            }, 'Tidak terdaftar! atau Account anda belum aktif.');

            if ($use_captcha == true) {
                $validator->addNewRule('verify_captcha', function ($field, $value, array $params) use ($gcaptcha_secret) {
                    $result = false;

                    if (isset($_POST['g-recaptcha-response'])) {
                        $recaptcha = new \ReCaptcha\ReCaptcha($gcaptcha_secret);
                        $resp = $recaptcha->verify($_POST['g-recaptcha-response'], $_SERVER['REMOTE_ADDR']);
                        $result = $resp->isSuccess();
                    }

                    return $result;

                }, 'Tidak tepat!');
            }

            $validator->rule('required', 'email');
            $validator->rule('email', 'email');
            $validator->rule('check_email_exist', 'email');

            if ($use_captcha == true) {
                $validator->rule('verify_captcha', 'captcha');
            }

            if ($validator->validate()) {
                $reset_key = md5(uniqid(rand(), true));
                $reset_expired_date = date('Y-m-d H:i:s', time() + 7200); // 2 jam
                $email_address = trim($_POST['email']);

                $q_member = $db->createQueryBuilder()
                    ->select('user_id', 'username')
                    ->from('users')
                    ->where('email = :email')
                    ->setParameter(':email', $email_address)
                    ->execute();

                $member = $q_member->fetch();

                $db->insert('users_reset_pwd', array(
                    'user_id' => $member['user_id'],
                    'reset_key' => $reset_key,
                    'expired_date' => $reset_expired_date,
                    'email_sent' => 'N',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 'N'
                ));

                $db->close();

                try {
                    $replacements = array();
                    $replacements[$email_address] = array(
                        '{email_address}' => $email_address,
                        '{request_reset_date}' => date('d-m-Y H:i:s'),
                        '{reset_path}' => $this->router->pathFor('membership-reset-password', array(
                            'uid' => $member['user_id'],
                            'reset_key' => $reset_key
                        )),
                        '{reset_expired_date}' => date('d-m-Y H:i:s', strtotime($reset_expired_date)),
                        '{base_url}' => $request->getUri()->getBaseUrl()
                    );

                    $message = Swift_Message::newInstance('PHP Indonesia - Konfirmasi lupa password')
                        ->setFrom(array($this->settings['email']['sender_email'] => $this->settings['email']['sender_name']))
                        ->setTo(array($email_address => $member['username']))
                        ->setBody(file_get_contents(APP_DIR.'protected'._DS_.'views'._DS_.'email'._DS_.'forgot-password-confirmation.txt'));

                    $mailer = $this->get('mailer');
                    $mailer->registerPlugin(new Swift_Plugins_DecoratorPlugin($replacements));
                    $mailer->send($message);

                    // Update email sent status
                    $db->update('users_reset_pwd', array('email_sent' => 'Y'), array(
                        'user_id' => $member['user_id'],
                        'reset_key' => $reset_key
                    ));

                    $db->close();

                    $this->flash->addMessage('success', $success_msg);
                } catch (Swift_TransportException $e) {
                    $this->flash->addMessage('success', $success_msg_alt);
                }

                return $response->withRedirect($this->router->pathFor('membership-login'), 302);

            } else {
                $this->flash->addMessage('warning', 'Masih ada isian-isian wajib yang belum anda isi. Atau masih ada isian yang belum diisi dengan benar');
            }
        }

        $this->view->addData(
            array(
                'page_title' => 'Membership',
                'sub_page_title' => 'Forgot Password',
                'enable_captcha' => $use_captcha
            ),
            'layouts::system'
        );

        return $this->view->render(
            $response,
            'membership/forgot-password',
            compact('gcaptcha_site_key', 'use_captcha')
        );
    }

    public function reset($request, $response, $args)
    {
        $q_reset_exist_count = $this->db->createQueryBuilder()
        ->select('COUNT(*) AS total_data')
        ->from('users_reset_pwd')
        ->where('user_id = :uid')
        ->andWhere('reset_key = :resetkey')
        ->andWhere('deleted = :d')
        ->andWhere('email_sent = :sent')
        ->andWhere('expired_date > NOW()')
        ->setParameter(':uid', $args['uid'])
        ->setParameter(':resetkey', $args['reset_key'])
        ->setParameter(':d', 'N')
        ->setParameter(':sent', 'Y')
        ->execute();

        $reset_exist_count = (int) $q_reset_exist_count->fetch()['total_data'];

        if ($reset_exist_count > 0) {
            $success_msg = 'Password baru sementara anda sudah dikirim ke email. Segera check email anda. Terimakasih ^_^';
            $success_msg_alt = 'Password baru sementara anda sudah dikirim ke email.<br /><br /><strong>Kemungkinan email akan sampai agak terlambat, karena email server kami sedang mengalami sedikit kendala teknis. Jika belum juga mendapatkan email, maka jangan ragu untuk laporkan kepada kami melalu email: report@phpindonesia.or.id</strong><br /><br />Terimakasih ^_^';

            // Fetch member basic info
            $q_member = $this->db->createQueryBuilder()
            ->select('username', 'email')->from('users')->where('user_id = :uid')
            ->setParameter(':uid', $args['uid'])->execute();

            $member = $q_member->fetch();
            $email_address = $member['email'];

            // Handle new temporary password
            $salt_pwd = $this->settings['salt_pwd'];
            $temp_pwd = substr(str_shuffle(md5(microtime())), 0, 10);
            $salted_temp_pwd = md5($salt_pwd.$temp_pwd);

            $this->db->update('users', array(
                'password' => $salted_temp_pwd,
                'modified' => date('Y-m-d H:i:s'),
                'modified_by' => 0
            ), array('user_id' => $args['uid']));

            $this->db->update('users_reset_pwd', array('deleted' => 'Y' ), array(
                'user_id' => $args['uid'],
                'reset_key' => $args['reset_key']
            ));

            $this->db->close();

            // Then send new temporary password to email
            try {
                $replacements = array();
                $replacements[$email_address] = array(
                    '{temp_pwd}' => $temp_pwd
                );

                $message = Swift_Message::newInstance('PHP Indonesia - Password baru sementara')
                ->setFrom(array($this->settings['email']['sender_email'] => $this->settings['email']['sender_name']))
                ->setTo(array($email_address => $member['username']))
                ->setBody(file_get_contents(APP_DIR.'protected'._DS_.'views'._DS_.'email'._DS_.'password-change-ok-confirmation.txt'));

                $mailer = $this->get('mailer');
                $mailer->registerPlugin(new Swift_Plugins_DecoratorPlugin($replacements));
                $mailer->send($message);

                $this->flash->addMessage('success', $success_msg);
                return $response->withStatus(302)->withHeader('Location', $this->router->pathFor('membership-login'));

            } catch (Swift_TransportException $e) {
                $this->flash->addMessage('success', $success_msg_alt);
                return $response->withStatus(302)->withHeader('Location', $this->router->pathFor('membership-login'));
            }

        } else {
            $this->flash->addMessage('error', 'Bad Request');
            return $response->withStatus(302)->withHeader('Location', $this->router->pathFor('membership-login'));
        }
    }
}
