<?php
namespace Da\User\Controller;

use Da\User\Event\FormEvent;
use Da\User\Event\SocialNetworkConnectEvent;
use Da\User\Event\UserEvent;
use Da\User\Factory\MailFactory;
use Da\User\Form\RegistrationForm;
use Da\User\Form\ResendForm;
use Da\User\Model\SocialNetworkAccount;
use Da\User\Model\User;
use Da\User\Query\SocialNetworkAccountQuery;
use Da\User\Query\UserQuery;
use Da\User\Service\AccountConfirmationService;
use Da\User\Service\EmailConfirmationService;
use Da\User\Service\ResendConfirmationService;
use Da\User\Service\UserConfirmationService;
use Da\User\Service\UserCreateService;
use Da\User\Service\UserRegisterService;
use Da\User\Traits\ContainerTrait;
use Da\User\Traits\ModuleTrait;
use Da\User\Validator\AjaxRequestModelValidator;
use Yii;
use yii\base\Module;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class RegistrationController extends Controller
{
    use ContainerTrait;

    protected $userQuery;
    protected $socialNetworkAccountQuery;

    /**
     * RegistrationController constructor.
     *
     * @param string $id
     * @param Module $module
     * @param UserQuery $userQuery
     * @param SocialNetworkAccountQuery $socialNetworkAccountQuery
     * @param array $config
     */
    public function __construct(
        $id,
        Module $module,
        UserQuery $userQuery,
        SocialNetworkAccountQuery $socialNetworkAccountQuery,
        array $config
    ) {

        $this->userQuery = $userQuery;
        $this->socialNetworkAccountQuery = $socialNetworkAccountQuery;
        parent::__construct($id, $module, $config);
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['register', 'connect'],
                        'roles' => ['?']
                    ],
                    [
                        'allow' => true,
                        'actions' => ['confirm', 'resend'],
                        'roles' => ['?', '@']
                    ],
                ],
            ],
        ];
    }

    public function actionRegister()
    {
        if(!$this->module->enableRegistration) {
            throw new NotFoundHttpException();
        }
        /** @var RegistrationForm $form */
        $form = $this->make(RegistrationForm::class);
        /** @var FormEvent $event */
        $event = $this->make(FormEvent::class, [$form]);

        $this->make(AjaxRequestModelValidator::class, [$form])->validate();

        if($form->load(Yii::$app->request->post()) && $form->validate()) {
            $this->trigger(UserEvent::EVENT_BEFORE_REGISTER, $event);

            $user = $this->make(User::class, [$form->attributes]);
            $user->setScenario('register');
            $mailService = MailFactory::makeWelcomeMailerService($user);

            if($this->make(UserRegisterService::class, [$user, $mailService])->run()) {
                Yii::$app->session->setFlash(
                    'info',
                    Yii::t(
                        'user',
                        'Your account has been created and a message with further instructions has been sent to your email'
                    )
                );
                return $this->render('message', [
                    'title' => Yii::t('user', 'Your account has been created')
                ]);
            }

            return $this->render('register', [
                'model'  => $form,
                'module' => $this->module,
            ]);
        }
    }

    public function actionConnect($code)
    {
        /** @var SocialNetworkAccount $account */
        $account = $this->socialNetworkAccountQuery->whereCode($code)->one();
        if ($account === null || $account->getIsConnected()) {
            throw new NotFoundHttpException();
        }

        /** @var User $user */
        $user = $this->make(
            User::class,
            ['scenario' => 'connect', 'username' => $account->username, 'email' => $account->email]
        );
        $event = $this->make(SocialNetworkConnectEvent::class, [$user, $account]);

        $this->make(AjaxRequestModelValidator::class, [$user])->validate();

        if ($user->load(Yii::$app->request->post())) {

            $this->trigger(SocialNetworkConnectEvent::EVENT_BEFORE_CONNECT, $event);

            $mailService = MailFactory::makeWelcomeMailerService($user);
            if ($this->make(UserCreateService::class, [$user, $mailService])->run()) {
                $account->connect($user);
                $this->trigger(SocialNetworkConnectEvent::EVENT_AFTER_CONNECT, $event);

                Yii::$app->user->login($user, $this->module->rememberLoginLifespan);

                return $this->goBack();
            }
        }

        return $this->render(
            'connect',
            [
                'model' => $user,
                'account' => $account,
            ]
        );
    }

    public function actionConfirm($id, $code)
    {
        $user = $this->userQuery->whereId($id)->one();

        if ($user === null || $this->module->enableEmailConfirmation === false) {
            throw new NotFoundHttpException();
        }

        /** @var UserEvent $event */
        $event = $this->make(UserEvent::class, [$user]);
        $userConfirmationService = $this->make(UserConfirmationService::class, [$user]);

        $this->trigger(UserEvent::EVENT_BEFORE_CONFIRMATION, $event);

        if ($this->make(AccountConfirmationService::class, [$code, $user, $userConfirmationService])->run()) {
            Yii::$app->user->login($user, $this->module->rememberLoginLifespan);
            Yii::$app->session->setFlash('success', Yii::t('user', 'Thank you, registration is now complete.'));

            $this->trigger(UserEvent::EVENT_AFTER_CONFIRMATION, $event);
        } else {
            Yii::$app->session->setFlash(
                'danger',
                Yii::t('user', 'The confirmation link is invalid or expired. Please try requesting a new one.')
            );
        }

        return $this->render(
            'message',
            [
                'title' => Yii::t('user', 'Account confirmation'),
                'module' => $this->module,
            ]
        );
    }

    public function actionResend()
    {
        if ($this->module->enableEmailConfirmation === false) {
            throw new NotFoundHttpException();
        }
        /** @var ResendForm $form */
        $form = $this->make(ResendForm::class);
        $event = $this->make(FormEvent::class, [$form]);

        $this->make(AjaxRequestModelValidator::class, [$form])->validate();

        if ($form->load(Yii::$app->request->post()) && $form->validate()) {
            $this->trigger(FormEvent::EVENT_BEFORE_RESEND, $event);
            /** @var User $user */
            $user = $this->userQuery->whereEmail($form->email)->one();
            $mailService = MailFactory::makeConfirmationMailerService($user);
            if ($this->make(ResendConfirmationService::class, [$user, $mailService])->run()) {
                $this->trigger(FormEvent::EVENT_AFTER_RESEND, $event);
                Yii::$app->session->setFlash(
                    'info',
                    Yii::t(
                        'user',
                        'A message has been sent to your email address. It contains a confirmation link that you must 
                        click to complete registration.'
                    )
                );
            } else {
                Yii::$app->session->setFlash(
                    'danger',
                    Yii::t(
                        'user',
                        'We couldn\'t re-send the mail to confirm your address. Please, verify is the correct email.'
                    )
                );
            }
        }
    }
}