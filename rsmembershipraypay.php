<?php
/**
 * @package       RSMembership!
 * @copyright (C) 2009-2020 www.rsjoomla.com
 * @license       GPL, http://www.gnu.org/licenses/gpl-2.0.html
 */
/**
 * @plugin RSMembership RayPay Payment
 * @author hanieh729
 */

ini_set('display_errors', 1);
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;

require_once JPATH_ADMINISTRATOR . '/components/com_rsmembership/helpers/rsmembership.php';

class plgSystemRSMembershipRayPay extends JPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        RSMembership::addPlugin( 'RayPay for RSMembership', 'rsmembershipraypay');
    }

    /**
     * call when payment starts
     *
     * @param $plugin
     * @param $data
     * @param $extra
     * @param $membership
     * @param $transaction
     * @param $html
     */
    public function onMembershipPayment($plugin, $data, $extra, $membership, $transaction, $html)
    {
        $app = JFactory::getApplication();
        $this->http = HttpFactory::getHttp();

        try {
            if ($plugin != 'rsmembershipraypay')
                return;

            $user_id = trim($this->params->get('user_id'));
            $acceptor_code = trim($this->params->get('acceptor_code'));

            $extra_total = 0;
            foreach ($extra as $row) {
                $extra_total += $row->price;
            }

            $amount = $transaction->price + $extra_total;
            $amount *= $this->params->get('currency') == 'rial' ? 1 : 10;

            $transaction->custom = md5($transaction->params . ' ' . time());
            if ($membership->activation == 2) {
                $transaction->status = 'completed';
            }
            $transaction->store();

            $callback = JURI::base() . 'index.php?option=com_rsmembership&raypayPayment=1&order_id=' .$transaction->id . '&';
            $callback = JRoute::_($callback, false);
            $invoice_id             = round(microtime(true) * 1000);
            $session  = JFactory::getSession();
            $session->set('transaction_custom', $transaction->custom);
            $session->set('membership_id', $membership->id);

            $data = array(
                'amount'       => strval($amount),
                'invoiceID'    => strval($invoice_id),
                'userID'       => $user_id,
                'redirectUrl'  => $callback,
                'factorNumber' => strval($transaction->id),
                'acceptorCode' => $acceptor_code,
                'email'        => !empty($data->email)? $data->email : '',
                'mobile'       => !empty($data->fields['phone'])? $data->fields['phone'] : '',
                'fullName'     => !empty($data->name)? $data->name : '',
                'comment'      => htmlentities( ' ???????????? ???????????? RSMembership ???? ?????????? ????????????  ' . $transaction->id, ENT_COMPAT, 'utf-8'),
            );

            $url  = 'http://185.165.118.211:14000/raypay/api/v1/Payment/getPaymentTokenWithUserID';
            $options = array('Content-Type' => 'application/json');
            $result = $this->http->post($url, json_encode($data, true), $options);
            $result = json_decode($result->body);
            $http_status = $result->StatusCode;


            if ( $http_status != 200 || empty($result) || empty($result->Data) )
            {
                $transaction->status = 'denied';
                $transaction->store();

                $msg = sprintf('?????? ?????????? ?????????? ????????????. ???? ??????: %s - ???????? ??????: %s', $http_status, $result->Message);
                RSMembership::saveTransactionLog($msg, $transaction->id);

                throw new Exception($msg);
            }

            RSMembership::saveTransactionLog( '???? ?????? ?????????? ???? ?????????? ????????????', $transaction->id );

            $access_token = $result->Data->Accesstoken;
            $terminal_id  = $result->Data->TerminalID;

            echo '<p style="color:#ff0000; font:18px Tahoma; direction:rtl;">???? ?????? ?????????? ???? ?????????? ??????????. ???????? ?????? ???????? ...</p>';
            echo '<form name="frmRayPayPayment" method="post" action=" https://mabna.shaparak.ir:8080/Pay ">';
            echo '<input type="hidden" name="TerminalID" value="' . $terminal_id . '" />';
            echo '<input type="hidden" name="token" value="' . $access_token . '" />';
            echo '<input class="submit" type="submit" value="????????????" /></form>';
            echo '<script>document.frmRayPayPayment.submit();</script>';


        }
        catch (Exception $e) {
            $app->redirect(JRoute::_(JURI::base() . 'index.php/component/rsmembership/view-membership-details/' . $membership->id, false), $e->getMessage(), 'error');
            exit;
        }
    }

    public function getLimitations() {
        $msg = '';
        return $msg;
    }

    /**
     * after payment completed
     * calls function onPaymentNotification()
     */
    public function onAfterDispatch()
    {
        $app = JFactory::getApplication();
        if ($app->input->getBoolean('raypayPayment')) {
            $this->onPaymentNotification($app);
        }
    }

    /**
     * process payment verification and approve subscription
     * @param $app
     */
    protected function onPaymentNotification($app)
    {
        $this->http = HttpFactory::getHttp();
        $jinput   = $app->input;
        $invoiceId = $jinput->get->get('?invoiceID', '', 'STRING');
        $orderId = $jinput->get->get('order_id', '', 'STRING');

        $session  = JFactory::getSession();
        $transaction_custom = $session->get('transaction_custom');

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->quoteName('#__rsmembership_transactions'))
            ->where($db->quoteName('status') . ' != ' . $db->quote('completed'))
            ->where($db->quoteName('custom') . ' = ' . $db->quote($transaction_custom));
        $db->setQuery($query);
        $transaction = @$db->loadObject();

        try {
            if ( empty( $invoiceId ) || empty( $orderId ) )
                throw new Exception( '?????? ?????????? ???????????? ???? ??????????' );

            if (!$transaction)
                throw new Exception( '?????????? ???????? ??????.' );


            $data = array('order_id' => $orderId);
            $url = 'http://185.165.118.211:14000/raypay/api/v1/Payment/checkInvoice?pInvoiceID=' . $invoiceId;;
            $options = array('Content-Type' => 'application/json');
            $result = $this->http->post($url, json_encode($data, true), $options);
            $result = json_decode($result->body);
            $http_status = $result->StatusCode;

            if ( $http_status != 200 )
            {
                $msg = sprintf('?????? ?????????? ?????????????? ????????????. ???? ??????: %s - ???????? ??????: %s', $http_status, $result->Message);
                throw new Exception($msg);
            }

            $state           = $result->Data->State;

            if ($state === 1) {
                $query->clear();
                $query->update($db->quoteName('#__rsmembership_transactions'))
                    ->set($db->quoteName('hash') . ' = ' . $db->quote($invoiceId))
                    ->where($db->quoteName('id') . ' = ' . $db->quote($transaction->id));

                $db->setQuery($query);
                $db->execute();

                $membership_id = $session->get('membership_id');

                if (!$membership_id)
                    throw new Exception( '?????????? ???????? ??????.');

                $query->clear()
                    ->select('activation')
                    ->from($db->quoteName('#__rsmembership_memberships'))
                    ->where($db->quoteName('id') . ' = ' . $db->quote((int)$membership_id));
                $db->setQuery($query);
                $activation = $db->loadResult();

                if ($activation) // activation == 0 => activation is manual
                {
                    RSMembership::approve($transaction->id);
                }

                $msg  = '???????????? ?????? ???? ???????????? ?????????? ????.';
                RSMembership::saveTransactionLog($msg, $transaction->id);

                $app->redirect(JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false), $msg, 'message');
            }

            $msg  = '???????????? ???????????? ???????? ??????. ?????????? ?????????? ?????????? ?????? ???? : ' . $invoiceId;
            throw new Exception($msg);

        } catch (Exception $e) {
            if($transaction){
                RSMembership::deny($transaction->id);
                RSMembership::saveTransactionLog($e->getMessage(), $transaction->id );
            }
            $app->enqueueMessage($e->getMessage(), 'error');
        }
    }
}
